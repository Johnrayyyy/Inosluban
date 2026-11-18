from __future__ import annotations

import hashlib
import io
import json
import os
import re
import uuid
from datetime import date, datetime
from typing import Any

import cv2
import numpy as np
import zxingcpp
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from pydantic import BaseModel, ConfigDict, Field

try:
    from rapidocr_onnxruntime import RapidOCR
except ImportError:
    RapidOCR = None


MAX_IMAGE_BYTES = int(os.getenv("NATIONAL_ID_MAX_IMAGE_BYTES", "5000000"))
MAX_PIXELS = int(os.getenv("NATIONAL_ID_MAX_PIXELS", "12000000"))
SERVICE_TOKEN = os.getenv("NATIONAL_ID_SERVICE_TOKEN", "")

app = FastAPI(title="Barangay Inosluban National ID pre-verification")
ocr_engine = RapidOCR() if RapidOCR is not None else None


class CheckResults(BaseModel):
    model_config = ConfigDict(extra="forbid")

    qr_decoded: bool = False
    front_pcn_match: bool | None = None
    front_last_name_match: bool | None = None


class VerificationResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    ok: bool
    status: str
    verification_id: str
    checks: CheckResults
    masked_pcn: str | None = None
    extracted: dict[str, str | None] = Field(default_factory=dict)
    review_reasons: list[str] = Field(default_factory=list)
    message_code: str


def _safe_verification_id(value: str | None) -> str:
    if value and re.fullmatch(r"[A-Za-z0-9._:-]{8,128}", value):
        return value
    return uuid.uuid4().hex


def _require_service_token(token: str | None) -> None:
    if SERVICE_TOKEN and token != SERVICE_TOKEN:
        raise HTTPException(status_code=401, detail="Invalid service credential.")


def _read_image(upload: UploadFile) -> np.ndarray:
    data = upload.file.read(MAX_IMAGE_BYTES + 1)
    if len(data) == 0 or len(data) > MAX_IMAGE_BYTES:
        raise ValueError("image_size")
    image = cv2.imdecode(np.frombuffer(data, dtype=np.uint8), cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError("image_decode")
    height, width = image.shape[:2]
    if width < 160 or height < 120 or width * height > MAX_PIXELS:
        raise ValueError("image_dimensions")
    return image


def _normalize_text(value: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", value.upper())


def _normalize_name(value: str) -> str:
    value = re.sub(r"\b(SR|JR|II|III|IV)\b", "", value.upper())
    return re.sub(r"[^A-Z]", "", value)


def _mask_pcn(value: str | None) -> str | None:
    if not value:
        return None
    normalized = re.sub(r"[^0-9A-Z]", "", value.upper())
    if len(normalized) < 4:
        return None
    return "*" * max(0, len(normalized) - 4) + normalized[-4:]


def _date_value(value: Any) -> str | None:
    if not isinstance(value, str):
        return None
    for fmt in ("%Y-%m-%d", "%d/%m/%Y", "%m/%d/%Y"):
        try:
            return datetime.strptime(value.strip(), fmt).date().isoformat()
        except ValueError:
            continue
    return None


def _find_value(payload: dict[str, Any], names: tuple[str, ...]) -> str | None:
    lowered = {str(key).lower(): value for key, value in payload.items()}
    for name in names:
        value = lowered.get(name.lower())
        if isinstance(value, str) and value.strip():
            return value.strip()
    return None


def _parse_qr_payload(raw: str) -> dict[str, str | None] | None:
    try:
        payload = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError):
        return None
    if not isinstance(payload, dict):
        return None

    pcn = _find_value(payload, ("pcn", "philSysCardNumber", "philidNumber"))
    last_name = _find_value(
        payload,
        ("lastName", "surname", "familyName", "n_l"),
    )
    if not pcn or not last_name:
        return None
    return {
        "pcn": pcn,
        "last_name": last_name,
        "first_name": _find_value(payload, ("firstName", "givenName", "n_f")),
        "middle_name": _find_value(payload, ("middleName", "n_m")),
        "birthdate": _date_value(
            _find_value(payload, ("birthdate", "dateOfBirth", "dob", "bd"))
        ),
        "gender": _find_value(payload, ("sex", "gender")),
    }


def _decode_qr(back_image: np.ndarray) -> dict[str, str | None] | None:
    variants = [back_image]
    gray = cv2.cvtColor(back_image, cv2.COLOR_BGR2GRAY)
    variants.append(gray)
    variants.append(cv2.adaptiveThreshold(
        gray,
        255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY,
        31,
        5,
    ))
    for image in variants:
        for result in zxingcpp.read_barcodes(image):
            raw = result.text
            parsed = _parse_qr_payload(raw)
            if parsed is not None:
                return parsed
    return None


def _ocr_front(front_image: np.ndarray) -> tuple[str, str]:
    if ocr_engine is None:
        return "", ""
    result, _ = ocr_engine(front_image)
    text = " ".join(str(item[1]) for item in (result or []))
    pcn_candidates = re.findall(r"(?:\d[\s-]*){12,20}", text)
    pcn = max(pcn_candidates, key=len, default="")
    return pcn, text


def _verify_images(
    front: np.ndarray,
    back: np.ndarray,
) -> VerificationResponse:
    verification_id = uuid.uuid4().hex
    checks = CheckResults()
    qr = _decode_qr(back)
    if qr is None:
        checks.qr_decoded = False
        return VerificationResponse(
            ok=True,
            status="PENDING_STAFF_REVIEW",
            verification_id=verification_id,
            checks=checks,
            review_reasons=["QR_UNREADABLE_OR_UNSUPPORTED"],
            message_code="QR_REQUIRES_STAFF_REVIEW",
        )

    checks.qr_decoded = True
    ocr_pcn, ocr_text = _ocr_front(front)
    qr_pcn = str(qr["pcn"])
    checks.front_pcn_match = (
        _normalize_text(ocr_pcn) == _normalize_text(qr_pcn)
        if ocr_pcn
        else None
    )
    checks.front_last_name_match = (
        _normalize_name(str(qr["last_name"])) in _normalize_name(ocr_text)
        if ocr_text
        else None
    )

    reasons: list[str] = []
    if checks.front_pcn_match is not True:
        reasons.append("FRONT_PCN_UNREADABLE_OR_MISMATCH")
    if checks.front_last_name_match is not True:
        reasons.append("FRONT_LAST_NAME_UNREADABLE_OR_MISMATCH")

    status = "PRE_VERIFIED" if not reasons else "PENDING_STAFF_REVIEW"
    return VerificationResponse(
        ok=True,
        status=status,
        verification_id=verification_id,
        checks=checks,
        masked_pcn=_mask_pcn(qr_pcn),
        extracted={
            "first_name": qr["first_name"],
            "middle_name": qr["middle_name"],
            "last_name": qr["last_name"],
            "birthdate": qr["birthdate"],
            "gender": qr["gender"],
        },
        review_reasons=reasons,
        message_code=(
            "PRE_VERIFICATION_COMPLETE"
            if status == "PRE_VERIFIED"
            else "PENDING_STAFF_REVIEW"
        ),
    )


@app.get("/health")
def health() -> dict[str, str]:
    return {
        "status": "ok",
        "ready": "true"
        if zxingcpp is not None and ocr_engine is not None
        else "degraded",
    }


@app.post("/verify-id", response_model=VerificationResponse)
def verify_id(
    front_image: UploadFile = File(...),
    back_image: UploadFile = File(...),
    verification_id: str | None = Form(default=None),
    service_token: str | None = Form(default=None),
) -> VerificationResponse:
    _require_service_token(service_token)
    try:
        front = _read_image(front_image)
        back = _read_image(back_image)
        response = _verify_images(front, back)
        response.verification_id = _safe_verification_id(
            verification_id or response.verification_id
        )
        return response
    except ValueError as error:
        raise HTTPException(
            status_code=422,
            detail=f"invalid_image_{error.args[0] if error.args else 'input'}",
        ) from error

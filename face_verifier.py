#!/usr/bin/env python3
"""Server-side face template enrollment and matching for the PHP portal."""

from __future__ import annotations

import base64
import binascii
import hashlib
import json
import os
import sys
import tempfile
from pathlib import Path

os.environ["OPENCV_IO_MAX_IMAGE_PIXELS"] = str(1280 * 960)

import cv2
import mediapipe as mp
import numpy as np


MAX_INPUT_BYTES = 2_000_000
MAX_IMAGE_BYTES = 150_000
FACE_SIZE = (160, 160)
MATCH_DISTANCE_LIMIT = 75.0
MIN_MATCHES = 2
MODEL_PATH = Path(__file__).parent / "storage" / "models" / "face_landmarker.task"
MODEL_SHA256 = "64184e229b263107bc2b804c6625db1341ff2bb731874b0bcc2fe6544e0bc9ff"


class FaceInputError(ValueError):
    pass


def _read_request() -> dict:
    raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
    if len(raw) > MAX_INPUT_BYTES:
        raise FaceInputError("Request is too large.")
    try:
        request = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise FaceInputError("Request is not valid JSON.") from error
    if not isinstance(request, dict):
        raise FaceInputError("Request must be a JSON object.")
    return request


def _decode_frames(raw_frames: object) -> list[np.ndarray]:
    if not isinstance(raw_frames, list) or len(raw_frames) != 5:
        raise FaceInputError("Capture all five camera steps and try again.")

    frames = []
    for raw_frame in raw_frames:
        if not isinstance(raw_frame, str) or len(raw_frame) > MAX_IMAGE_BYTES * 2:
            raise FaceInputError("A camera image is missing or too large.")
        try:
            encoded = base64.b64decode(raw_frame, validate=True)
        except (binascii.Error, ValueError) as error:
            raise FaceInputError("A camera image is invalid.") from error
        if not encoded or len(encoded) > MAX_IMAGE_BYTES:
            raise FaceInputError("A camera image is missing or too large.")
        try:
            image = cv2.imdecode(
                np.frombuffer(encoded, dtype=np.uint8),
                cv2.IMREAD_COLOR,
            )
        except cv2.error as error:
            raise FaceInputError("A camera image could not be decoded.") from error
        if image is None:
            raise FaceInputError("A camera image could not be decoded.")
        height, width = image.shape[:2]
        if width < 160 or height < 120 or width > 1280 or height > 960:
            raise FaceInputError("Camera image dimensions are not supported.")
        frames.append(image)
    return frames


def _face_regions(frames: list[np.ndarray]) -> tuple[list[np.ndarray], list[float]]:
    if not MODEL_PATH.is_file():
        raise RuntimeError("The MediaPipe face-landmarker model is missing.")
    if hashlib.sha256(MODEL_PATH.read_bytes()).hexdigest() != MODEL_SHA256:
        raise RuntimeError("The MediaPipe face-landmarker model checksum is invalid.")

    regions = []
    yaws = []
    options = mp.tasks.vision.FaceLandmarkerOptions(
        base_options=mp.tasks.BaseOptions(model_asset_path=str(MODEL_PATH)),
        running_mode=mp.tasks.vision.RunningMode.IMAGE,
        num_faces=2,
        min_face_detection_confidence=0.65,
    )
    with mp.tasks.vision.FaceLandmarker.create_from_options(options) as detector:
        for image in frames:
            height, width = image.shape[:2]
            rgb_image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
            result = detector.detect(
                mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb_image)
            )
            if not result.face_landmarks or len(result.face_landmarks) != 1:
                raise FaceInputError(
                    "Keep only one face in view and capture the steps again."
                )

            landmarks = result.face_landmarks[0]
            xs = [point.x * width for point in landmarks]
            ys = [point.y * height for point in landmarks]
            left, right = min(xs), max(xs)
            top, bottom = min(ys), max(ys)
            face_width = right - left
            face_height = bottom - top
            if face_width < 48 or face_height < 48:
                raise FaceInputError("Move closer to the camera and try again.")

            margin_x = face_width * 0.18
            margin_y = face_height * 0.18
            x1 = max(0, int(left - margin_x))
            x2 = min(width, int(right + margin_x))
            y1 = max(0, int(top - margin_y))
            y2 = min(height, int(bottom + margin_y))
            crop = image[y1:y2, x1:x2]
            gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
            gray = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
            regions.append(cv2.resize(gray, FACE_SIZE, interpolation=cv2.INTER_AREA))

            eye_distance = abs(landmarks[263].x - landmarks[33].x)
            if eye_distance < 0.05:
                raise FaceInputError("The camera view is too angled. Face the camera.")
            eye_midpoint = (landmarks[263].x + landmarks[33].x) / 2
            yaws.append((landmarks[1].x - eye_midpoint) / eye_distance)

    return regions, yaws


def _validate_pose_sequence(yaws: list[float]) -> None:
    center_yaws = [yaws[index] for index in (0, 2, 4)]
    turn_yaws = [yaws[index] for index in (1, 3)]
    if any(abs(yaw) > 0.2 for yaw in center_yaws):
        raise FaceInputError("Look straight at the camera for the center steps.")
    if (
        any(abs(yaw) < 0.16 for yaw in turn_yaws)
        or turn_yaws[0] * turn_yaws[1] >= 0
    ):
        raise FaceInputError(
            "Turn your head clearly in both requested directions, then retry."
        )


def _make_recognizer() -> cv2.face.LBPHFaceRecognizer:
    return cv2.face.LBPHFaceRecognizer_create(
        radius=1,
        neighbors=8,
        grid_x=8,
        grid_y=8,
        threshold=MATCH_DISTANCE_LIMIT,
    )


def _enroll(regions: list[np.ndarray]) -> str:
    recognizer = _make_recognizer()
    center_regions = [regions[index] for index in (0, 2, 4)]
    recognizer.train(
        np.asarray(center_regions, dtype=np.uint8),
        np.ones(len(center_regions), dtype=np.int32),
    )
    with tempfile.TemporaryDirectory(prefix="inosluban-face-") as directory:
        model_path = Path(directory) / "template.xml"
        recognizer.write(str(model_path))
        return base64.b64encode(model_path.read_bytes()).decode("ascii")


def _verify(regions: list[np.ndarray], raw_template: object) -> bool:
    if not isinstance(raw_template, str) or len(raw_template) > 700_000:
        raise RuntimeError("The stored face template is invalid.")
    try:
        template = base64.b64decode(raw_template, validate=True)
    except (binascii.Error, ValueError) as error:
        raise RuntimeError("The stored face template is invalid.") from error
    if not template or len(template) > 500_000:
        raise RuntimeError("The stored face template is invalid.")

    with tempfile.TemporaryDirectory(prefix="inosluban-face-") as directory:
        model_path = Path(directory) / "template.xml"
        model_path.write_bytes(template)
        recognizer = _make_recognizer()
        try:
            recognizer.read(str(model_path))
        except cv2.error as error:
            raise RuntimeError("The stored face template could not be read.") from error

        matches = 0
        for index in (0, 2, 4):
            label, distance = recognizer.predict(regions[index])
            if label == 1 and distance <= MATCH_DISTANCE_LIMIT:
                matches += 1
        return matches >= MIN_MATCHES


def main() -> int:
    try:
        request = _read_request()
        mode = request.get("mode")
        frames = _decode_frames(request.get("frames"))
        regions, yaws = _face_regions(frames)
        _validate_pose_sequence(yaws)

        if mode == "enroll":
            response = {"ok": True, "template": _enroll(regions)}
        elif mode == "verify":
            matched = _verify(regions, request.get("template"))
            response = {
                "ok": matched,
                "message": "Face verified."
                if matched
                else "Face not recognized. Try again.",
            }
        else:
            raise FaceInputError("Unknown face verification operation.")

        print(json.dumps(response, separators=(",", ":")))
        return 0
    except FaceInputError as error:
        print(
            json.dumps(
                {"ok": False, "message": str(error), "retryable": True},
                separators=(",", ":"),
            )
        )
        return 0
    except Exception as error:  # Fail closed; PHP logs only this service error.
        print(
            json.dumps(
                {"ok": False, "message": "Face verification is temporarily unavailable."},
                separators=(",", ":"),
            )
        )
        print(f"face verifier error: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

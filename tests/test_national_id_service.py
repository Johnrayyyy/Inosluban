import unittest
from unittest.mock import patch

from fastapi import HTTPException

from national_id_service.app import (
    _mask_pcn,
    _normalize_name,
    _normalize_text,
    _parse_qr_payload,
    _require_service_token,
    _verify_images,
    health,
)


class NationalIdServiceTests(unittest.TestCase):
    def test_supported_json_qr_payload_is_limited_to_required_fields(self):
        result = _parse_qr_payload(
            '{"pcn":"1234-5678-9012-3456","lastName":"Dela Cruz",'
            '"firstName":"Juan","dateOfBirth":"2000-01-02","sex":"M",'
            '"address":"not retained"}'
        )
        self.assertEqual(result["last_name"], "Dela Cruz")
        self.assertEqual(result["birthdate"], "2000-01-02")
        self.assertNotIn("address", result)

    def test_unknown_qr_payload_is_not_interpreted(self):
        self.assertIsNone(_parse_qr_payload("signed-or-encrypted-envelope"))

    def test_short_national_id_qr_field_names_are_supported(self):
        result = _parse_qr_payload(
            '{"pcn":"1234-5678-9012-3456","n_l":"Sample",'
            '"n_f":"Resident","n_m":"Middle","bd":"2000-01-02"}'
        )
        self.assertEqual(result["last_name"], "Sample")
        self.assertEqual(result["first_name"], "Resident")
        self.assertEqual(result["middle_name"], "Middle")
        self.assertEqual(result["birthdate"], "2000-01-02")

    def test_comparison_normalization_and_masking(self):
        self.assertEqual(_normalize_text("1234-5678 9012"), "123456789012")
        self.assertEqual(_normalize_name("Dela-Cruz, Jr."), "DELACRUZ")
        self.assertEqual(_mask_pcn("1234-5678-9012-3456"), "************3456")

    def test_front_and_back_checks_do_not_require_or_return_selfie_results(self):
        qr = {
            "pcn": "1234567890123456",
            "last_name": "Resident",
            "first_name": None,
            "middle_name": None,
            "birthdate": None,
            "gender": None,
        }
        with (
            patch("national_id_service.app._decode_qr", return_value=qr),
            patch(
                "national_id_service.app._ocr_front",
                return_value=("1234567890123456", "Resident"),
            ),
        ):
            result = _verify_images(object(), object())

        self.assertEqual(result.status, "PRE_VERIFIED")
        self.assertEqual(
            set(result.checks.model_dump()),
            {"qr_decoded", "front_pcn_match", "front_last_name_match"},
        )

    def test_configured_service_token_is_checked(self):
        with (
            patch("national_id_service.app.REQUIRE_SERVICE_TOKEN", True),
            patch("national_id_service.app.SERVICE_TOKEN", "test-token"),
        ):
            _require_service_token("test-token")
            with self.assertRaises(HTTPException) as error:
                _require_service_token("wrong-token")

        self.assertEqual(error.exception.status_code, 401)

    def test_required_service_token_fails_closed_when_missing(self):
        with (
            patch("national_id_service.app.REQUIRE_SERVICE_TOKEN", True),
            patch("national_id_service.app.SERVICE_TOKEN", ""),
        ):
            with self.assertRaises(HTTPException) as error:
                _require_service_token(None)

        self.assertEqual(error.exception.status_code, 503)

    def test_health_is_not_ready_when_required_token_is_missing(self):
        with (
            patch("national_id_service.app.REQUIRE_SERVICE_TOKEN", True),
            patch("national_id_service.app.SERVICE_TOKEN", ""),
            patch("national_id_service.app.ocr_engine", object()),
        ):
            with self.assertRaises(HTTPException) as error:
                health()

        self.assertEqual(error.exception.status_code, 503)

    def test_health_is_ready_when_dependencies_and_token_are_configured(self):
        with (
            patch("national_id_service.app.REQUIRE_SERVICE_TOKEN", True),
            patch("national_id_service.app.SERVICE_TOKEN", "test-token"),
            patch("national_id_service.app.ocr_engine", object()),
        ):
            self.assertEqual(health(), {"status": "ok", "ready": "true"})


if __name__ == "__main__":
    unittest.main()

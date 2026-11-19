import unittest
from unittest.mock import patch

from national_id_service.app import (
    _mask_pcn,
    _normalize_name,
    _normalize_text,
    _parse_qr_payload,
    _verify_images,
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


if __name__ == "__main__":
    unittest.main()

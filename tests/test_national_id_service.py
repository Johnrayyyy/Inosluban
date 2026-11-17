import unittest

from national_id_service.app import (
    _mask_pcn,
    _normalize_name,
    _normalize_text,
    _parse_qr_payload,
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

    def test_comparison_normalization_and_masking(self):
        self.assertEqual(_normalize_text("1234-5678 9012"), "123456789012")
        self.assertEqual(_normalize_name("Dela-Cruz, Jr."), "DELACRUZ")
        self.assertEqual(_mask_pcn("1234-5678-9012-3456"), "************3456")


if __name__ == "__main__":
    unittest.main()

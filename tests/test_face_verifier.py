import unittest

import cv2
import numpy as np

from face_verifier import (
    FaceInputError,
    _decode_frames,
    _enroll,
    _face_regions,
    _make_recognizer,
    _verify,
    _validate_pose_sequence,
)


class FaceVerifierTests(unittest.TestCase):
    def test_pose_sequence_requires_center_and_opposite_turns(self):
        _validate_pose_sequence([0.02, 0.25, -0.01, -0.24, 0.03])

        with self.assertRaises(FaceInputError):
            _validate_pose_sequence([0.02, 0.25, -0.01, 0.24, 0.03])

        with self.assertRaises(FaceInputError):
            _validate_pose_sequence([0.25, 0.25, -0.01, -0.24, 0.03])

    def test_lbph_recognizes_the_images_used_to_train_its_template(self):
        rng = np.random.default_rng(42)
        samples = rng.integers(0, 256, size=(3, 160, 160), dtype=np.uint8)
        recognizer = _make_recognizer()
        recognizer.train(samples, np.ones(3, dtype=np.int32))

        label, distance = recognizer.predict(samples[0])

        self.assertEqual(label, 1)
        self.assertLessEqual(distance, 75.0)

    def test_serialized_template_matches_its_enrollment_samples(self):
        rng = np.random.default_rng(42)
        regions = [
            rng.integers(0, 256, size=(160, 160), dtype=np.uint8)
            for _ in range(5)
        ]
        template = _enroll(regions)

        self.assertTrue(_verify(regions, template))

    def test_blank_frames_are_not_treated_as_faces(self):
        blank_frame = np.zeros((240, 320, 3), dtype=np.uint8)

        with self.assertRaises(FaceInputError):
            _face_regions([blank_frame.copy() for _ in range(5)])

    def test_frame_decoder_requires_exactly_five_frames(self):
        with self.assertRaises(FaceInputError):
            _decode_frames([])


if __name__ == "__main__":
    unittest.main()

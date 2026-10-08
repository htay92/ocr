"""PaddleOCR-specific checks; the contract tests are shared (engines/common/tests)."""

import main


def test_version_names_the_model():
    assert main.app.state.engine.name == "paddle"
    assert "PP-OCRv5" in main.VERSION

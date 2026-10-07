import pytest
from fastapi.testclient import TestClient

import main
from conftest import assert_schema


@pytest.fixture
def client():
    # No "with": the lifespan (real model load) is not started in these tests.
    yield TestClient(main.app)
    main.state.update(ocr=None, error=None)


def test_health_is_not_ready_while_the_model_loads(client):
    response = client.get("/health")

    assert response.status_code == 503
    assert response.json()["code"] == "not_ready"
    assert response.json()["ready"] is False


def test_health_reports_engine_and_version_when_ready(client):
    main.state["ocr"] = object()

    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"engine": "paddle", "version": main.VERSION, "ready": True}
    assert_schema(response.json(), "health")
    assert "PP-OCRv5" in main.VERSION


def test_health_reports_a_failed_model_load(client):
    main.state["error"] = "boom"

    response = client.get("/health")

    assert response.status_code == 503
    assert response.json()["message"] == "boom"

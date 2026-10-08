"""GET /health (spec 3.2), without loading the model."""

import pytest
from fastapi.testclient import TestClient

import main
from conftest import assert_schema

ENGINE = main.app.state.engine


@pytest.fixture
def client():
    # No "with": the lifespan (real model load) is not started in these tests.
    model, error = main.app.state.model, main.app.state.error
    main.app.state.model, main.app.state.error = None, None
    yield TestClient(main.app)
    main.app.state.model, main.app.state.error = model, error


def test_health_is_not_ready_while_the_model_loads(client):
    response = client.get("/health")

    assert response.status_code == 503
    assert response.json()["code"] == "not_ready"
    assert response.json()["ready"] is False


def test_health_reports_engine_and_version_when_ready(client):
    main.app.state.model = object()

    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"engine": ENGINE.name, "version": ENGINE.version, "ready": True}
    assert_schema(response.json(), "health")


def test_health_reports_a_failed_model_load(client):
    main.app.state.error = "boom"

    response = client.get("/health")

    assert response.status_code == 503
    assert response.json()["message"] == "boom"

<?php

it('serves the home page and the health check', function () {
    $this->get('/')->assertOk();
    $this->get('/up')->assertOk();
});

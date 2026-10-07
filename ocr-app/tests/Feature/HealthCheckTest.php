<?php

it('serves the health check', function () {
    $this->get('/up')->assertOk();
});

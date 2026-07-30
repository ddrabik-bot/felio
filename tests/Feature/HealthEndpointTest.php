<?php

it('returns an HTTP 200 response from the health endpoint', function () {
    $this->get('/health')->assertOk();
});

<?php

it('does not expose the unused welcome page', function () {
    $this->get('/')->assertNotFound();
    expect(view()->exists('welcome'))->toBeFalse();
});

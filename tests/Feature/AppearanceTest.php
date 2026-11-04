<?php

it('defaults to the light theme for a first-time visitor', function () {
    $this->get(route('home'))->assertViewHas('appearance', 'light');
});

it('keeps the theme a visitor chose', function () {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->get(route('home'))
        ->assertViewHas('appearance', 'dark');
});

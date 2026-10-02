<?php

it('redirige la raíz al panel', function () {
    $this->get('/')->assertRedirect('/panel');
});

it('manda al invitado a la pantalla de entrada', function () {
    $this->get('/panel')->assertRedirect(route('login'));
});

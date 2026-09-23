<?php

return [

    'temporary_file_upload' => [
        // Скан до 100 страниц (ТЗ 6.2) не укладывается в умолчание Livewire 12 МБ; предел ниже client_max_body_size 64m в kb-nginx
        'rules' => ['required', 'file', 'max:61440'],
    ],

];

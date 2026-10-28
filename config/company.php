<?php

/*
 * Çap şablonlarında (sifariş, təhvil-təslim, satış fakturası) görünən şirkət rekvizitləri.
 */
return [
    'name' => env('COMPANY_NAME', env('APP_NAME', 'Valvo Analitika')),
    'voen' => env('COMPANY_VOEN'),
    'address' => env('COMPANY_ADDRESS'),
    'phone' => env('COMPANY_PHONE'),
    'bank' => env('COMPANY_BANK'),
    'iban' => env('COMPANY_IBAN'),
];

<?php

/*
|--------------------------------------------------------------------------
| Validation Language Lines — Bahasa Indonesia
|--------------------------------------------------------------------------
|
| Pesan validasi tampil langsung di antarmuka, jadi bahasa antarmuka utama
| (Bahasa Indonesia) juga dipakai di sini. Aturan yang belum dipetakan akan
| jatuh ke pesan bawaan Laravel dalam bahasa Inggris.
|
*/

return [

    'accepted' => ':attribute harus disetujui.',
    'after' => ':attribute harus berisi tanggal setelah :date.',
    'array' => ':attribute harus berupa larik.',
    'boolean' => ':attribute harus bernilai ya atau tidak.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Password saat ini salah.',
    'date' => ':attribute bukan tanggal yang valid.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus terdiri dari :digits digit.',
    'email' => ':attribute harus berupa alamat email yang valid.',
    'exists' => ':attribute yang dipilih tidak valid.',
    'gt' => [
        'array' => ':attribute harus memiliki lebih dari :value item.',
        'file' => ':attribute harus lebih besar dari :value KB.',
        'numeric' => ':attribute harus lebih besar dari :value.',
        'string' => ':attribute harus lebih dari :value karakter.',
    ],
    'gte' => [
        'array' => ':attribute harus memiliki :value item atau lebih.',
        'file' => ':attribute harus :value KB atau lebih.',
        'numeric' => ':attribute harus :value atau lebih.',
        'string' => ':attribute harus :value karakter atau lebih.',
    ],
    'in' => ':attribute yang dipilih tidak valid.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'lt' => [
        'array' => ':attribute harus memiliki kurang dari :value item.',
        'file' => ':attribute harus kurang dari :value KB.',
        'numeric' => ':attribute harus kurang dari :value.',
        'string' => ':attribute harus kurang dari :value karakter.',
    ],
    'lte' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :value item.',
        'file' => ':attribute tidak boleh lebih dari :value KB.',
        'numeric' => ':attribute tidak boleh lebih dari :value.',
        'string' => ':attribute tidak boleh lebih dari :value karakter.',
    ],
    'max' => [
        'array' => ':attribute tidak boleh memiliki lebih dari :max item.',
        'file' => ':attribute tidak boleh lebih besar dari :max KB.',
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'min' => [
        'array' => ':attribute harus memiliki minimal :min item.',
        'file' => ':attribute harus minimal :min KB.',
        'numeric' => ':attribute harus minimal :min.',
        'string' => ':attribute harus minimal :min karakter.',
    ],
    'not_in' => ':attribute yang dipilih tidak valid.',
    'numeric' => ':attribute harus berupa angka.',
    'present' => ':attribute wajib ada.',
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':attribute wajib diisi.',
    'required_if' => ':attribute wajib diisi bila :other adalah :value.',
    'required_with' => ':attribute wajib diisi bila :values ada.',
    'required_without' => ':attribute wajib diisi bila :values tidak ada.',
    'same' => ':attribute dan :other harus sama.',
    'string' => ':attribute harus berupa teks.',
    'unique' => ':attribute sudah digunakan.',
    'url' => ':attribute harus berupa URL yang valid.',

    'custom' => [
        'email' => [
            'unique' => 'Email ini sudah terdaftar.',
        ],
    ],

    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'current_password' => 'password saat ini',
        'password_confirmation' => 'konfirmasi password',
        'name' => 'nama',
        'remember' => 'ingat saya',
    ],

];

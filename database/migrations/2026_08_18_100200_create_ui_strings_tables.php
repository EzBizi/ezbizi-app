<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UI strings as data (§8). English and Spanish ship as rows so a third
 * language is an insert, not a deploy of PHP enums.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_locales', function (Blueprint $table) {
            $table->string('code', 16)->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('ui_strings', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 16);
            $table->string('key');
            $table->text('text');
            $table->timestamps();

            $table->unique(['locale', 'key']);
            $table->foreign('locale')->references('code')->on('ui_locales')->cascadeOnDelete();
        });

        $now = now();

        DB::table('ui_locales')->insert([
            ['code' => 'en', 'name' => 'English', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'es', 'name' => 'Español', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $en = [
            'shell.sign_in' => 'Sign in',
            'shell.sign_in_prompt' => 'Sign in to your workspace.',
            'shell.create_account' => 'Create an account',
            'shell.settings' => 'Settings',
            'shell.your_account' => 'Your account',
            'shell.sign_out' => 'Sign out',
            'shell.email' => 'Email',
            'shell.password' => 'Password',
            'shell.language' => 'Language',
        ];

        $es = [
            'shell.sign_in' => 'Iniciar sesión',
            'shell.sign_in_prompt' => 'Inicie sesión en su espacio de trabajo.',
            'shell.create_account' => 'Crear una cuenta',
            'shell.settings' => 'Ajustes',
            'shell.your_account' => 'Su cuenta',
            'shell.sign_out' => 'Cerrar sesión',
            'shell.email' => 'Correo',
            'shell.password' => 'Contraseña',
            'shell.language' => 'Idioma',
        ];

        $rows = [];
        foreach (['en' => $en, 'es' => $es] as $locale => $strings) {
            foreach ($strings as $key => $text) {
                $rows[] = [
                    'locale' => $locale,
                    'key' => $key,
                    'text' => $text,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('ui_strings')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_strings');
        Schema::dropIfExists('ui_locales');
    }
};

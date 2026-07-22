<?php

use AzGuard\Database\Schema\MorphColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $t = config('az-guard.table_names');
        Schema::create($t['roles'], function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('class_name')->nullable();
            $table->integer('level')->default(0);
            $table->timestamps();
        });
        Schema::create($t['model_has_roles'], function (Blueprint $table) use ($t) {
            $table->foreignId('role_id')->constrained($t['roles'])->cascadeOnDelete();
            MorphColumns::add($table, 'model');
        });
        Schema::create($t['model_has_scopes'], function (Blueprint $table) {
            $table->id();
            MorphColumns::add($table, 'model');
            MorphColumns::add($table, 'scope_entity', nullable: true);
            $table->string('scope_class');
            $table->timestamps();
        });
    }
};

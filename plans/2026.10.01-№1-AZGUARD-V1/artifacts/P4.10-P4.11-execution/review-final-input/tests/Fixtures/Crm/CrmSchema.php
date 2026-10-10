<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class CrmSchema extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('cities', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->foreignId('city_id')->constrained('cities');
            $t->boolean('is_root');
            $t->timestamp('locked_at')->nullable();
        });
        Schema::create('organization_user', function (Blueprint $t): void {
            $t->foreignId('organization_id')->constrained('organizations');
            $t->foreignId('user_id')->constrained('users');
            $t->primary(['organization_id', 'user_id']);
        });
        Schema::create('projects', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained('organizations');
            $t->foreignId('city_id')->constrained('cities');
            $t->string('region');
            $t->boolean('is_active');
            $t->unique(['id', 'organization_id']);
        });
        Schema::create('project_members', function (Blueprint $t): void {
            $t->foreignId('project_id')->constrained('projects');
            $t->foreignId('user_id')->constrained('users');
            $t->string('role');
            $t->primary(['project_id', 'user_id', 'role']);
        });
        Schema::create('clients', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organization_id')->constrained('organizations');
            $t->unsignedBigInteger('project_id');
            $t->boolean('do_not_call');
            $t->foreignId('owner_user_id')->constrained('users');
            $t->foreign(['project_id', 'organization_id'])->references(['id', 'organization_id'])->on('projects');
        });
    }
}

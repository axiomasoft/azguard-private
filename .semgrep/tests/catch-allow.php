<?php

function catchAllow($gate): bool
{
    // ruleid: azguard.catch-allow
    try {
        return $gate->check();
    } catch (\Throwable $error) {
        report($error);

        return true;
    }
}

function catchAllowException($gate): Decision
{
    // ruleid: azguard.catch-allow
    try {
        return $gate->decide();
    } catch (Exception $error) {
        return Decision::allow('fallback');
    }
}

function catchAllowEffect($gate): Effect
{
    // ruleid: azguard.catch-allow
    try {
        return $gate->effect();
    } catch (Throwable $error) {
        return Effect::Allow;
    }
}

function catchDeny($gate): bool
{
    // ok: azguard.catch-allow
    try {
        return $gate->check();
    } catch (\Throwable $error) {
        report($error);

        return false;
    }
}

function catchOtherReturnsTrue($gate): bool
{
    // ok: azguard.catch-allow
    try {
        return $gate->check();
    } catch (LookupMissing $error) {
        return true;
    }
}

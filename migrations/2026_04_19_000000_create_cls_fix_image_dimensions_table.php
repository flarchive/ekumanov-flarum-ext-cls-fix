<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('cls_fix_image_dimensions', function (Blueprint $table) {
    // sha256 hex of the normalized URL; keeps the PK narrow and index-friendly
    // even when URLs are long (signed S3, redirect chains, etc.).
    $table->char('url_hash', 64)->primary();
    $table->text('url');
    $table->unsignedInteger('width')->nullable();
    $table->unsignedInteger('height')->nullable();
    $table->timestamp('updated_at')->useCurrent();
});

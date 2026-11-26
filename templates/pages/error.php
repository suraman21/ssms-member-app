<?php
/**
 * Error page template.
 *
 * @var callable(mixed): string $e
 * @var int|null $status
 * @var string|null $message
 */
$code = $status ?? 500;
$text = $message ?? 'An unexpected error occurred.';
?>
<section class="fk-card fk-error-card" aria-labelledby="error-heading">
    <h1 id="error-heading" class="fk-card-title">Error <?= $e((string) $code) ?></h1>
    <p class="fk-card-subtitle"><?= $e($text) ?></p>
    <div class="fk-actions">
        <a href="/" class="fk-btn fk-btn-primary">Return Home</a>
    </div>
</section>

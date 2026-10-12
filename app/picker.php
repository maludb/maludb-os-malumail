<?php
declare(strict_types=1);

/**
 * The record picker (design-system reference record-picker.md): a form chooses a RECORD — a supplier, an item, a
 * customer, anything a person adds rows to without limit — through one searchable modal instead of a <select>.
 *
 *   picker_field([...])                 the field: a hidden input under the select's old name and id + the button that opens the picker
 *   form_picker(...)  (app/picker.php)      the same inside the standard col-lg-4 / col-lg-8 form row
 *   picker_source($key)                 one entry of app/pickers.php — the registry of what can be picked
 *   picker_label($pdo, $key, $id)       the chosen record's label (first render, a 422 re-render)
 *   GET /pick/?source=&q=&page=         html/pick/index.php answers shared/picker-rows.php; assets/js/record-picker.js drives the modal
 *
 * A source (app/pickers.php) is ['title', 'noun', 'params' => [declared filter keys => 'int'],
 *   'gate'   => fn(array $params): ?string  (null = allowed, else the refusal's words — the screens' own authorization, per source),
 *   'search' => fn(PDO, string $q, array $params, int $page): array{rows, total, page, page_size, pages},   rows: [id, label, detail?, badge?, color?]
 *   'label'  => fn(PDO, int|string $id): ?string  (an int — every key is a bigint)].
 * `search` reuses the feature's own query function; the endpoint whitelists `params` and never sees anything else.
 */

function picker_sources(): array
{
    if (!isset($GLOBALS['__picker_sources'])) {
        $GLOBALS['__picker_sources'] = require __DIR__ . '/pickers.php';
    }
    return $GLOBALS['__picker_sources'];
}

function picker_source(string $key): ?array
{
    return picker_sources()[$key] ?? null;
}

/**
 * The label of the chosen record, or null when the id names nothing (the form then shows the placeholder).
 * Every record of MaluMail a picker chooses is keyed by a bigint: a digit string reaches the source as an int, anything else is no choice.
 */
function picker_label(PDO $pdo, string $source, int|string|null $id): ?string
{
    $spec = picker_source($source);
    if ($spec === null || $id === null || $id === '') {
        return null;
    }
    $id = (string) $id;
    return ctype_digit($id) && strlen($id) < 19 ? ($spec['label'])($pdo, (int) $id) : null;
}

/**
 * The field. $spec: id (the hidden input's id — what hx-include selectors name), name, source, value; optional label
 * (looked up through the source when absent), placeholder, title (the modal's), search (the search box's placeholder),
 * params (fixed filters, key => value), include (CSS selectors whose live values filter the search; their names must
 * be declared params), required (no Clear button), disabled, invalid, labelledby (the row label's id), extra
 * (attributes on the hidden input — a dependent refresh's hx-get … hx-trigger="change"), append (more input-group
 * buttons after the search button), create (['label', 'panel' => a selector to open] or ['label', 'url']).
 */
function picker_field(array $spec): string
{
    $id = (string) $spec['id'];
    $source = picker_source((string) $spec['source']) ?? throw new InvalidArgumentException('Unknown picker source ' . $spec['source']);
    $value = $spec['value'] ?? null;
    $value = $value === null || $value === '' ? '' : (string) $value;
    $label = $spec['label'] ?? ($value !== '' ? picker_label(db(), (string) $spec['source'], $value) : null);
    if ($value !== '' && $label === null) {
        $value = '';                                         // an id that names nothing is no choice
    }
    $placeholder = (string) ($spec['placeholder'] ?? ('Choose ' . ($source['noun'] === 'item' ? 'an' : 'a') . ' ' . $source['noun']));
    $required = !empty($spec['required']);
    $disabled = !empty($spec['disabled']);
    $data = ' data-picker-source="' . e($spec['source']) . '" data-picker-title="' . e($spec['title'] ?? $source['title']) . '"'
        . ' data-picker-placeholder="' . e($placeholder) . '" data-picker-search="' . e($spec['search'] ?? ('Search ' . lcfirst($source['title']) . '…')) . '"';
    if (!empty($spec['params'])) {
        $data .= ' data-picker-params="' . e(json_encode($spec['params'], JSON_THROW_ON_ERROR)) . '"';
    }
    if (!empty($spec['include'])) {
        $data .= ' data-picker-include="' . e($spec['include']) . '"';
    }
    if (!empty($spec['create']['label'])) {
        $data .= ' data-picker-create-label="' . e($spec['create']['label']) . '"'
            . (isset($spec['create']['panel']) ? ' data-picker-create-panel="' . e($spec['create']['panel']) . '"' : '')
            . (isset($spec['create']['url']) ? ' data-picker-create-url="' . e($spec['create']['url']) . '"' : '');
    }
    $invalid = !empty($spec['invalid']) ? ' is-invalid' : '';
    $labelledby = isset($spec['labelledby']) ? ' aria-labelledby="' . e($spec['labelledby']) . ' ' . e($id) . '-open"' : '';
    return '<div class="input-group record-picker-field" id="' . e($id) . '-picker"' . $data . '>'
        . '<input type="hidden" name="' . e($spec['name']) . '" id="' . e($id) . '" value="' . e($value) . '" data-picker-value' . ($spec['extra'] ?? '') . ' />'
        . '<button type="button" class="form-control text-start record-picker-open' . $invalid . '" id="' . e($id) . '-open" aria-haspopup="dialog" aria-controls="record-picker"'
        . $labelledby . ($required ? ' aria-required="true"' : '') . ($disabled ? ' disabled' : '') . '>'
        . '<span class="record-picker-label' . ($value === '' ? ' text-muted' : '') . '">' . e($value === '' ? $placeholder : $label) . '</span></button>'
        . ($required || $disabled ? '' : '<button type="button" class="btn btn-light-brand record-picker-clear' . ($value === '' ? ' d-none' : '') . '" id="' . e($id) . '-clear" aria-label="Clear"><i class="feather-x"></i></button>')
        . '<button type="button" class="btn btn-light-brand record-picker-search-btn" id="' . e($id) . '-search-btn" aria-label="Search"' . ($disabled ? ' disabled' : '') . '><i class="feather-search"></i></button>'
        . ($spec['append'] ?? '')
        . '</div>';
}

/**
 * A result page (25 rows, the endpoint's shape) from a list a feature's query already produced: the rows are filtered by the query
 * text (a case-insensitive match on the label and the detail) and sliced. For the short lists of this application (brands, suppliers,
 * departments) whose existing query returns the whole set in one go.
 * $rows: [id, label, detail?, badge?, color?].
 */
function picker_result_from_list(array $rows, string $q, int $page, int $size = 25): array
{
    if ($q !== '') {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => mb_stripos((string) $r['label'], $q) !== false || mb_stripos((string) ($r['detail'] ?? ''), $q) !== false));
    }
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $size));
    $page = min(max(1, $page), $pages);
    return ['rows' => array_slice($rows, ($page - 1) * $size, $size), 'total' => $total, 'page' => $page, 'page_size' => $size, 'pages' => $pages];
}

/**
 * The picker inside the standard form row (col-lg-4 label / col-lg-8 field): $prefix-$name is the hidden input's id, $opts are
 * picker_field()'s (params, include, required, create, help, last). Errors by field name show under the field.
 */
function form_picker(string $prefix, string $name, string $label, string $source, mixed $value, array $errors = [], array $opts = []): string
{
    $id = $prefix . '-field-' . str_replace('_', '-', $name);
    $help = $opts['help'] ?? null;
    unset($opts['help'], $opts['last']);
    return '<div class="row mb-3 align-items-center" id="' . e($id) . '-row"><div class="col-lg-4"><label class="fw-semibold" id="' . e($id) . '-label">' . e($label) . '</label></div><div class="col-lg-8">'
        . picker_field(['id' => $id, 'name' => $name, 'source' => $source, 'value' => $value, 'invalid' => isset($errors[$name]), 'labelledby' => $id . '-label'] + $opts)
        . (isset($errors[$name]) ? '<div class="invalid-feedback d-block">' . e($errors[$name]) . '</div>' : '')
        . ($help !== null ? '<div class="form-text">' . e($help) . '</div>' : '') . '</div></div>';
}

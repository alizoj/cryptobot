<?php

declare(strict_types=1);

/**
 * Shared UI kit for the trading backend views — registers the CSS used by the
 * dashboard, scan-preview and 4h-scan pages so they look consistent. Rendered
 * once per page via $this->render('_kit').
 *
 * @var yii\web\View $this
 */

$css = <<<CSS
.tr-toolbar .btn { white-space: nowrap; }

.kpi-card {
    border: 0;
    border-radius: .7rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.09);
}
.kpi-card .kpi-label {
    font-size: .7rem; text-transform: uppercase; letter-spacing: .05em;
    color: var(--bs-secondary-color); margin-bottom: .15rem;
}
.kpi-card .kpi-val { font-size: 1.55rem; font-weight: 600; line-height: 1.05; }
.kpi-card .kpi-sub { font-size: .78rem; color: var(--bs-secondary-color); }

.card-section { border: 0; border-radius: .7rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); }

.tr-table { margin-bottom: 0; }
.tr-table thead th {
    position: sticky; top: 0; z-index: 2;
    background: var(--bs-body-bg);
    font-size: .7rem; text-transform: uppercase; letter-spacing: .03em;
    color: var(--bs-secondary-color); border-bottom: 2px solid var(--bs-border-color);
}
.tr-table td { vertical-align: middle; }
.tr-table tbody tr:hover { background: var(--bs-tertiary-bg); }

.num { font-variant-numeric: tabular-nums; font-family: var(--bs-font-monospace); }

tr.row-long  td:first-child { box-shadow: inset 3px 0 0 var(--bs-success); }
tr.row-short td:first-child { box-shadow: inset 3px 0 0 var(--bs-danger); }

.setup-badge { font-weight: 600; font-size: .72rem; letter-spacing: .02em; }

.pnl-pos { color: var(--bs-success); font-weight: 600; }
.pnl-neg { color: var(--bs-danger);  font-weight: 600; }

.section-h { font-size: 1.05rem; font-weight: 600; margin: 1.6rem 0 .6rem; }
.section-h .count { color: var(--bs-secondary-color); font-weight: 400; }
CSS;

$this->registerCss($css);

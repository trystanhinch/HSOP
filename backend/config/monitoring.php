<?php

/**
 * Milestone 6A.4 / Phase 10 — Owner monitoring / System Health / alert wiring.
 */
return [
    /** Default aggregation window for GET /api/admin/monitoring/summary */
    'summary_window_hours' => (int) env('MONITORING_SUMMARY_WINDOW_HOURS', 24),

    /** Gmail intake considered stale when last_fetched_at older than this (hours). */
    'gmail_staleness_hours' => (int) env('MONITORING_GMAIL_STALENESS_HOURS', 2),
];

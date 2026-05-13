<?php

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * Retención de activity_log: 90 días.
     *
     * Antes: 365 días. La inconsistencia era que routes/console.php YA
     * ejecutaba `activitylog:prune --days=90` diariamente, así que el TTL
     * operativo real era 90, no 365. Alinear el config evita confusión y
     * el caso de invocar el comando sin --days (que caía a 365).
     *
     * Cálculo a escala: ~30k-50k filas/día con el objetivo de 10k
     * facturas/día → 90 días ≈ 3-5M filas en activity_log. Manejable con
     * los índices agregados en la migración 2026_05_13_120000.
     */
    'delete_records_older_than_days' => 90,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject returns soft deleted models.
     */
    'subject_returns_soft_deleted_models' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     */
    'activity_model' => \Spatie\Activitylog\Models\Activity::class,

    /*
     * This is the name of the table that will be created by the migration and
     * used by the Activity model shipped with this package.
     */
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    /*
     * This is the database connection that will be used by the migration and
     * the Activity model shipped with this package. In case it's not set
     * Laravel's database.default will be used instead.
     */
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];

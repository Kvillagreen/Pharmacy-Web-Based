<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (glob(__DIR__.'/../app/Models/v1/*.php') as $file) {
    $class = 'App\\Models\\v1\\'.pathinfo($file, PATHINFO_FILENAME);
    $model = new $class();
    $reflection = new ReflectionClass($class);
    $relationships = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || $method->getNumberOfRequiredParameters() > 0) {
            continue;
        }

        try {
            $result = $method->invoke($model);
            if ($result instanceof Illuminate\Database\Eloquent\Relations\Relation) {
                $relationships[] = $method->getName().':'.class_basename($result);
            }
        } catch (Throwable) {
            // Accessors and environment-dependent methods are not relationships.
        }
    }

    echo json_encode([
        'class' => $class,
        'table' => $model->getTable(),
        'primary_key' => $model->getKeyName(),
        'fillable' => $model->getFillable(),
        'hidden' => $model->getHidden(),
        'casts' => $model->getCasts(),
        'relationships' => $relationships,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;
}

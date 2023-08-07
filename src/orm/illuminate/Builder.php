<?php
/**
 * Created by PhpStorm
 * Date 2022/10/8 9:56.
 */

namespace Chance\Log\orm\illuminate;

use Chance\Log\facades\IlluminateOrmLog;
use Chance\Log\facades\OperationLog;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Builder extends \Illuminate\Database\Query\Builder
{
    public function insert(array $values): bool
    {
        $result = parent::insert($values);

        $this->insertLog($values);

        return $result;
    }

    public function insertGetId(array $values, $sequence = null): int
    {
        $id = parent::insertGetId($values, $sequence);

        $this->insertLog($values);

        return $id;
    }

    public function insertOrIgnore(array $values): int
    {
        $result = parent::insertOrIgnore($values);

        $this->insertLog($values);

        return $result;
    }

    public function update(array $values): int
    {
        if (IlluminateOrmLog::status()) {
            $oldData = $this->get()->toArray();
            if (!empty($oldData)) {
                $model = $this->generateModel();
                if (count($oldData) > 1) {
                    IlluminateOrmLog::batchUpdated($model, $oldData, $values);
                } else {
                    IlluminateOrmLog::updated($model, (array) $oldData[0], $values);
                }
            }
        }

        return parent::update($values);
    }

    public function delete($id = null): int
    {
        $this->deleteLog($id);

        return parent::delete($id);
    }

    public function truncate(): void
    {
        $this->deleteLog();
        parent::truncate();
    }

    /**
     * Generate model object.
     */
    private function generateModel(): Model
    {
        $name = $this->from;

        /** @var Connection $connection */
        $connection = $this->getConnection();
        $database = $connection->getDatabaseName();
        $table = $connection->getTablePrefix() . $name;

        $mapping = [
            OperationLog::getTableModelMapping(),
            include __DIR__ . '/../../../cache/table-model-mapping.php',
        ];
        foreach ($mapping as $map) {
            if (is_array($map) && isset($map[$database][$table]) && class_exists($map[$database][$table])) {
                return new $map[$database][$table]();
            }
        }

        $modelNamespace = $connection->getConfig('modelNamespace') ?: 'app\\model';
        $className = trim($modelNamespace, '\\') . '\\' . Str::studly($name);
        if (class_exists($className)) {
            $model = new $className();
        } else {
            $model = new DbModel();
            $model->setQuery($connection);
            $model->setTable($name);
            $model->logKey = $connection->getConfig('logKey') ?: $model->getKeyName();
        }

        return $model;
    }

    private function insertLog(array $values): void
    {
        if (IlluminateOrmLog::status()) {
            $model = $this->generateModel();
            if (is_array(reset($values))) {
                IlluminateOrmLog::batchCreated($model, $values);
            } else {
                /** @var Connection $connection */
                $connection = $this->getConnection();
                $id = $connection->getPdo()->lastInsertId();
                $pk = $model->getKeyName();
                $values[$pk] = $id;
                IlluminateOrmLog::created($model, $values);
            }
        }
    }

    private function deleteLog($id = null): void
    {
        if (IlluminateOrmLog::status()) {
            if (!empty($id)) {
                $data = [(array) $this->find($id)];
            } else {
                $data = $this->get()->toArray();
            }

            if (!empty($data)) {
                $model = $this->generateModel();
                if (count($data) > 1) {
                    IlluminateOrmLog::batchDeleted($model, $data);
                } else {
                    IlluminateOrmLog::deleted($model, (array) $data[0]);
                }
            }
        }
    }
}

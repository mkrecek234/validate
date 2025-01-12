<?php

declare(strict_types=1);

namespace Atk4\Validate;

use Atk4\Data\Model;

/**
 * Controller class for Agile Data model to enable validations.
 *
 * Use https://github.com/vlucas/valitron under the hood.
 *
 * $v = new \Atk4\Validate\Validator($model);
 */
class Validator
{
    /** @var Model */
    public $model;

    /**
     * Array of rules in following format which is natively supported by Valitron mapFieldsRules():
     *  [
     *      'foo' => [
     *          ['required'],
     *          ['integer', 'message'=>'test 1'],
     *      ],
     *      'bar' => [
     *          ['email'],
     *          ['lengthBetween', 4, 10, 'message'=>'test 2'],
     *      ],
     *  ];.
     *
     * @var array
     */
    public $rules = [];

    /**
     * Array of conditional rules in following format:
     *  [
     *      [$conditions, $then_rules, $else_rules],
     *  ].
     *
     * $conditions - array of conditions
     * $then_rules - array in $this->rules format which will be used if conditions are met
     * $else_rules - array in $this->rules format which will be used if conditions are not met
     *
     * @var array
     */
    public $if_rules = [];

    /**
     * Initialization.
     */
    public function __construct(Model $model)
    {
        $this->model = $model;

        if (property_exists($model, 'validator') && !isset($model->validator)) {
            $model->validator = $this;
        }

        $model->onHook($model::HOOK_VALIDATE, \Closure::fromCallable([$this, 'validate']));
    }

    /**
     * Set one rule.
     *
     * @param array|string|callable $rules
     *
     * @return $this
     */
    public function rule(string $field, $rules)
    {
        $this->rules[$field] = array_merge(
            isset($this->rules[$field]) ? $this->rules[$field] : [],
            $this->_normalizeRules($rules)
        );

        return $this;
    }

    /**
     * Set multiple rules.
     *
     * @param array $hash Array of [$field=>$rules]
     *
     * @return $this
     */
    public function rules(array $hash)
    {
        foreach ($hash as $field => $rules) {
            $this->rule($field, $rules);
        }

        return $this;
    }

    /**
     * Set conditional rules.
     *
     * @return $this
     */
    public function if(array $conditions, array $then_hash, array $else_hash = [])
    {
        $this->if_rules[] = [
            $conditions,
            $this->_normalizeRules($then_hash),
            $this->_normalizeRules($else_hash),
        ];

        return $this;
    }

    /**
     * Normalize rule-set.
     *
     * @param array|string|callable $rules
     *
     * @return array or arrays
     */
    protected function _normalizeRules($rules): array
    {
        $rules = (array) $rules;
        foreach ($rules as $key => $rule) {
            $rules[$key] = (array) $rule;
        }

        return $rules;
    }

    /**
     * Runs all validations.
     *
     * @return array|null
     */
    public function validate(Model $model, string $intent = null)
    {
        // initialize Validator, set data
        $v = new \Valitron\Validator($model->get());

        // prepare array of all rules we have to validate
        // this should also include respective rules from $this->if_rules.
        $all_rules = $this->rules;

        foreach ($this->if_rules as $row) {
            list($conditions, $then_hash, $else_hash) = $row;

            $test = true;
            foreach ($conditions as $field => $value) {
                $test = $test && ($model->get($field) === $value);
            }

            $all_rules = array_merge_recursive($all_rules, $test ? $then_hash : $else_hash);
        }

        // set up Valitron rules
        $v->mapFieldsRules($all_rules);

        // validate and if errors then format them to fit Atk4 error format
        if ($v->validate() !== true) {
            $errors = [];
            foreach ($v->errors() as $key => $e) {
                if (!isset($errors[$key])) {
                    $errors[$key] = array_pop($e);
                }
            }

            return $errors;
        }
    }
}

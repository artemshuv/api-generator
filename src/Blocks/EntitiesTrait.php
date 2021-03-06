<?php

namespace SoliDry\Blocks;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use League\Fractal\Resource\ResourceAbstract;
use SoliDry\Extension\ApiController;
use SoliDry\Extension\BaseFormRequest;
use SoliDry\Extension\BaseModel;
use SoliDry\Extension\JSONApiInterface;
use SoliDry\Helpers\Classes;
use SoliDry\Helpers\ConfigHelper as conf;
use SoliDry\Helpers\ConfigOptions;
use SoliDry\Helpers\Json;
use SoliDry\Types\DefaultInterface;
use SoliDry\Types\DirsInterface;
use SoliDry\Types\ModelsInterface;
use SoliDry\Types\PhpInterface;
use SoliDry\Types\ApiInterface;

/**
 * Class EntitiesTrait
 *
 * @package SoliDry\Blocks
 * @property ApiController entity
 * @property BaseFormRequest $formRequest
 * @property ApiController props
 * @property BaseModel model
 * @property ApiController modelEntity
 * @property ConfigOptions configOptions
 *
 */
trait EntitiesTrait
{
    /**
     * Gets form request entity fully qualified path
     *
     * @param string $version
     * @param string $object
     * @return string
     */
    public function getFormRequestEntity(string $version, string $object) : string
    {
        return DirsInterface::MODULES_DIR . PhpInterface::BACKSLASH . strtoupper($version) .
            PhpInterface::BACKSLASH . DirsInterface::HTTP_DIR .
            PhpInterface::BACKSLASH .
            DirsInterface::FORM_REQUEST_DIR . PhpInterface::BACKSLASH .
            $object .
            DefaultInterface::FORM_REQUEST_POSTFIX;
    }

    /**
     *  Sets all props/entities needed to process request
     */
    protected function setEntities() : void
    {
        $this->entity      = Classes::cutEntity(Classes::getObjectName($this), DefaultInterface::CONTROLLER_POSTFIX);
        $formRequestEntity  = $this->getFormRequestEntity(conf::getModuleName(), $this->entity);
        $this->formRequest = new $formRequestEntity();
        $this->props       = get_object_vars($this->formRequest);
        $this->modelEntity = Classes::getModelEntity($this->entity);
        $this->model       = new $this->modelEntity();
    }

    /**
     * Save bulk transactionally, if there are some errors - rollback
     *
     * @param array $jsonApiAttributes
     * @return ResourceAbstract
     * @throws \SoliDry\Exceptions\AttributesException
     */
    protected function saveBulk(array $jsonApiAttributes) : ResourceAbstract
    {
        $meta       = [];
        $collection = new Collection();

        try {
            DB::beginTransaction();
            foreach ($jsonApiAttributes as $jsonObject) {

                $this->model = new $this->modelEntity();

                // FSM initial state check
                if ($this->configOptions->isStateMachine() === true) {
                    $this->checkFsmCreate($jsonObject);
                }

                // spell check
                if ($this->configOptions->isSpellCheck() === true) {
                    $meta[] = $this->spellCheck($jsonObject);
                }

                // fill in model
                foreach ($this->props as $k => $v) {
                    // request fields should match FormRequest fields
                    if (isset($jsonObject[$k])) {
                        $this->model->$k = $jsonObject[$k];
                    }
                }

                // set bit mask
                if (true === $this->configOptions->isBitMask()) {
                    $this->setMaskCreate($jsonObject);
                }

                $collection->push($this->model);
                $this->model->save();
                // jwt
                if ($this->configOptions->getIsJwtAction() === true) {
                    $this->createJwtUser(); // !!! model is overridden
                }

                // set bit mask from model -> response
                if (true === $this->configOptions->isBitMask()) {
                    $this->model = $this->setFlagsCreate();
                }
            }
            DB::commit();
        } catch (\PDOException $e) {
            echo $e->getTraceAsString();
            DB::rollBack();
        }

        return Json::getResource($this->formRequest, $collection, $this->entity, true, $meta);
    }

    /**
     * Mutates/Updates a bulk by applying it to transaction/rollback procedure
     *
     * @param array $jsonApiAttributes
     * @return ResourceAbstract
     * @throws \SoliDry\Exceptions\AttributesException
     */
    protected function mutateBulk(array $jsonApiAttributes) : ResourceAbstract
    {
        $meta       = [];
        $collection = new Collection();

        try {
            DB::beginTransaction();
            foreach ($jsonApiAttributes as $jsonObject) {

                $model = $this->getEntity($jsonObject[JSONApiInterface::CONTENT_ID]);

                // FSM transition check
                if ($this->configOptions->isStateMachine() === true) {
                    $this->checkFsmUpdate($jsonObject, $model);
                }

                // spell check
                if ($this->configOptions->isSpellCheck() === true) {
                    $meta[] = $this->spellCheck($jsonObject);
                }

                $this->processUpdate($model, $jsonObject);
                $collection->push($model);
                $model->save();

                // set bit mask
                if (true === $this->configOptions->isBitMask()) {
                    $this->setFlagsUpdate($model);
                }

            }
            DB::commit();
        } catch (\PDOException $e) {
            echo $e->getTraceAsString();
            DB::rollBack();
        }

        return Json::getResource($this->formRequest, $collection, $this->entity, true, $meta);
    }

    /**
     * Deltes bulk by applying it to transaction/rollback procedure
     *
     * @param array $jsonApiAttributes
     */
    public function removeBulk(array $jsonApiAttributes) : void
    {
        try {
            DB::beginTransaction();

            foreach ($jsonApiAttributes as $jsonObject) {
                $model = $this->getEntity($jsonObject[JSONApiInterface::CONTENT_ID]);

                if ($model === null) {
                    DB::rollBack();
                    Json::outputErrors(
                        [
                            [
                                JSONApiInterface::ERROR_TITLE => 'There is no such id: ' . $jsonObject[JSONApiInterface::CONTENT_ID],
                                JSONApiInterface::ERROR_DETAIL => 'There is no such id: ' . $jsonObject[JSONApiInterface::CONTENT_ID] . ' or model was already deleted - transaction has been rolled back.'
                            ],
                        ]
                    );
                }

                $model->delete();
            }

            DB::commit();
        } catch (\PDOException $e) {
            echo $e->getTraceAsString();
            DB::rollBack();
        }
    }

    /**
     * Gets the relations of entity or null
     * @param string $objectName
     *
     * @return mixed
     */
    private function getRelationType(string $objectName)
    {
        if (empty($this->generator->types[$objectName][ApiInterface::RAML_PROPS]
                  [ApiInterface::RAML_RELATIONSHIPS][ApiInterface::RAML_TYPE]) === false
        ) {
            return trim(
                $this->generator->types[$objectName][ApiInterface::RAML_PROPS]
                [ApiInterface::RAML_RELATIONSHIPS][ApiInterface::RAML_TYPE]
            );
        }

        return null;
    }

    /**
     * Sets use stmt for Soft Delete op on model Entity
     */
    private function setUseSoftDelete() : void
    {
        if ($this->isSoftDelete()) {
            $this->setUse(Classes::getObjectName(SoftDeletes::class), true, true);
        }
    }

    /**
     * Sets property for Soft Delete op on model Entity
     */
    private function setPropSoftDelete() : void
    {
        if ($this->isSoftDelete()) {
            $this->createPropertyArray(ModelsInterface::PROPERTY_DATES, PhpInterface::PHP_MODIFIER_PROTECTED, [ModelsInterface::COLUMN_DEL_AT]);
        }
    }
}
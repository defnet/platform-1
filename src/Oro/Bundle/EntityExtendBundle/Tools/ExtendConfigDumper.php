<?php

namespace Oro\Bundle\EntityExtendBundle\Tools;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

use Oro\Bundle\EntityBundle\ORM\OroEntityManager;

use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;

use Oro\Bundle\EntityExtendBundle\Extend\ExtendManager;
use Oro\Bundle\EntityExtendBundle\Mapping\ExtendClassMetadataFactory;

class ExtendConfigDumper
{
    const ENTITY         = 'Extend\\Entity\\';
    const FIELD_PREFIX   = 'field_';
    const DEFAULT_PREFIX = 'default_';

    /**
     * @var string
     */
    protected $cacheDir;

    /**
     * @var OroEntityManager
     */
    protected $em;

    /**
     * @param OroEntityManager $em
     * @param string           $cacheDir
     */
    public function __construct(OroEntityManager $em, $cacheDir)
    {
        $this->cacheDir = $cacheDir;
        $this->em       = $em;
    }

    /**
     * @param null $className
     */
    public function updateConfig($className = null)
    {
        $this->clear();

        $extendProvider = $this->em->getExtendManager()->getConfigProvider();

        if ($className && $extendProvider->hasConfig($className)) {
            $config = $extendProvider->getConfig($className);
            if ($config->is('is_extend') && $config->is('upgradeable')) {
                $this->checkSchema($config);
            }
        } else {
            $configs = $extendProvider->getConfigs();
            foreach ($configs as $config) {
                if ($config->is('is_extend') && $config->is('upgradeable')) {
                    $this->checkSchema($config);
                }
            }

        }

        $this->clear();
    }

    public function dump()
    {
        $yml            = [];
        $extendProvider = $this->em->getExtendManager()->getConfigProvider();
        $configs        = $extendProvider->getConfigs();
        foreach ($configs as $config) {
            if ($schema = $config->get('schema')) {
                $yml[$config->getId()->getClassName()] = $schema;
            }
        }

        if ($yml) {
            file_put_contents(
                $this->cacheDir . '/entity_config.yml',
                Yaml::dump($yml, 8)
            );
        }
    }

    public function clear()
    {
        $filesystem = new Filesystem();
        if ($filesystem->exists($this->cacheDir)) {
            $filesystem->remove([$this->cacheDir]);
        }

        $filesystem->mkdir($this->cacheDir . '/Extend/Entity');

        /** @var ExtendClassMetadataFactory $metadataFactory */
        $metadataFactory = $this->em->getMetadataFactory();
        $metadataFactory->clearCache();
    }

    protected function checkSchema(ConfigInterface $entityConfig)
    {
        $extendProvider = $this->em->getExtendManager()->getConfigProvider();
        $className      = $entityConfig->getId()->getClassName();
        $doctrine       = [];

        if (strpos($className, self::ENTITY) !== false) {
            $entityName            = $className;
            $type                  = 'Custom';
            $doctrine[$entityName] = [
                'type'   => 'entity',
                'table'  => 'oro_extend_' . strtolower(str_replace('\\', '', $entityName)),
                'fields' => [
                    'id' => ['type' => 'integer', 'id' => true, 'generator' => ['strategy' => 'AUTO']]
                ],
            ];
        } else {
            $entityName            = $entityConfig->get('extend_class');
            $type                  = 'Extend';
            $doctrine[$entityName] = [
                'type'   => 'mappedSuperclass',
                'fields' => [],
            ];
        }

        $entityState = $entityConfig->get('state');

        $schema             = $entityConfig->get('schema');
        $properties         = array();
        $relationProperties = $schema ? $schema['relation'] : array();
        $defaultProperties  = array();
        $addRemoveMethods   = array();

        if ($fieldConfigs = $extendProvider->getConfigs($className)) {
            foreach ($fieldConfigs as $fieldConfig) {
                if ($fieldConfig->is('extend')) {
                    $fieldName = self::FIELD_PREFIX . $fieldConfig->getId()->getFieldName();
                    $fieldType = $fieldConfig->getId()->getFieldType();

                    if (in_array($fieldType, ['oneToMany', 'manyToOne', 'manyToMany'])) {
                        $relationProperties[$fieldName] = $fieldConfig->getId()->getFieldName();
                        if ($fieldType != 'manyToOne') {
                            $defaultName = self::DEFAULT_PREFIX . $fieldConfig->getId()->getFieldName();

                            $defaultProperties[$defaultName] = $defaultName;
                            $addRemoveMethods[$fieldName]    = array('self' => $fieldConfig->getId()->getFieldName());
                        }
                    } else {
                        $properties[$fieldName] = $fieldConfig->getId()->getFieldName();

                        $doctrine[$entityName]['fields'][$fieldName]['code']      = $fieldName;
                        $doctrine[$entityName]['fields'][$fieldName]['type']      = $fieldType;
                        $doctrine[$entityName]['fields'][$fieldName]['nullable']  = true;
                        $doctrine[$entityName]['fields'][$fieldName]['length']    = $fieldConfig->get('length');
                        $doctrine[$entityName]['fields'][$fieldName]['precision'] = $fieldConfig->get('precision');
                        $doctrine[$entityName]['fields'][$fieldName]['scale']     = $fieldConfig->get('scale');
                    }
                }

                if ($fieldConfig->get('state') != ExtendManager::STATE_DELETED) {
                    $fieldConfig->set('state', ExtendManager::STATE_ACTIVE);
                }

                if ($fieldConfig->get('state') == ExtendManager::STATE_DELETED) {
                    $fieldConfig->set('is_deleted', true);
                }

                $extendProvider->persist($fieldConfig);
            }
        }

        $extendProvider->flush();

        $entityConfig->set('state', $entityState);
        if ($entityConfig->get('state') == ExtendManager::STATE_DELETED) {
            $entityConfig->set('is_deleted', true);
        } else {
            $entityConfig->set('state', ExtendManager::STATE_ACTIVE);
        }

        $relations = $entityConfig->get('relation') ? : [];
        foreach ($relations as &$relation) {
            if ($relation['field_id']) {
                $relation['assign'] = true;
                if (isset($addRemoveMethods[self::FIELD_PREFIX . $relation['field_id']->getFieldName()])
                    && $relation['target_field_id']
                ) {
                    $addRemoveMethods[self::FIELD_PREFIX . $relation['field_id']->getFieldName()]['target']
                        = $relation['target_field_id']->getFieldName();
                }

                $this->checkRelation($relation['target_entity'], $relation['field_id']);
            }
        }
        $entityConfig->set('relation', $relations);


        $schema = [
            'class'    => $className,
            'entity'   => $entityName,
            'type'     => $type,
            'property' => $properties,
            'relation' => $relationProperties,
            'default'  => $defaultProperties,
            'addremove'=> $addRemoveMethods,
            'doctrine' => $doctrine,
        ];

        if ($type == 'Extend') {
            $schema['parent']  = get_parent_class($className);
            $schema['inherit'] = get_parent_class($schema['parent']);
        }

        $entityConfig->set('schema', $schema);

        $extendProvider->persist($entityConfig);
        $extendProvider->flush();
    }

    protected function checkRelation($targetClass, $fieldId)
    {
        $extendProvider = $this->em->getExtendManager()->getConfigProvider();
        $targetConfig   = $extendProvider->getConfig($targetClass);

        $relations = $targetConfig->get('relation') ? : [];
        $schema    = $targetConfig->get('schema') ? : [];

        foreach ($relations as &$relation) {
            if ($relation['target_field_id'] == $fieldId) {
                $relation['assign'] = true;
                $relationFieldId    = $relation['field_id'];

                if ($relation['owner'] && count($schema)) {
                    $schema['relation'][self::FIELD_PREFIX . $relationFieldId->getFieldName()] =
                        $relationFieldId->getFieldName();
                }
            }
        }

        $targetConfig->set('relation', $relations);
        $targetConfig->set('schema', $schema);

        $extendProvider->persist($targetConfig);
    }

    /**
     * Get Entity Identifier By a class name
     *
     * @param $className
     * @return string
     */
    protected function getEntityIdentifier($className)
    {
        // Extend entity always have "id" identifier
        if (strpos($className, self::ENTITY) !== false) {
            return 'id';
        }

        return $this->em->getClassMetadata($className)->getSingleIdentifierColumnName();
    }
}

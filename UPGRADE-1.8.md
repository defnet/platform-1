UPGRADE FROM 1.7 to 1.8
=======================

####PropertyAccess Component
- Removed `Oro\Component\PropertyAccess\PropertyPath` and `Oro\Component\PropertyAccess\PropertyPathInterface`, `Symfony\Component\PropertyAccess\PropertyPath` and `Symfony\Component\PropertyAccess\PropertyPathInterface` should be used instead
- Removed `Oro\Component\PropertyAccess\Exception` namespace, `Symfony\Component\PropertyAccess\Exception` is used

####ImportExportBundle
 - `Oro\Bundle\ImportExportBundle\Context\ContextInterface` added $incrementBy integer parameter for methods: incrementReadCount, incrementAddCount, incrementUpdateCount, incrementReplaceCount, incrementDeleteCount, incrementErrorEntriesCount

####WorkflowBundle
 Migrate conditions logic to ConfigExpression component:
 - Removed `Oro\Bundle\WorkflowBundle\Model\Condition\ConditionInterface`, `Oro\Component\ConfigExpression\ExpressionInterface` should be used instead
 - Removed `Oro\Bundle\WorkflowBundle\Model\Condition\ConditionFactory`, `Oro\Component\ConfigExpression\ExpressionFactory` should be used instead
 - Removed `Oro\Bundle\WorkflowBundle\Model\Condition\ConditionAssembler`, `Oro\Component\ConfigExpression\ExpressionAssembler` should be used instead
 - Removed all conditions in `Oro\Bundle\WorkflowBundle\Model\Condition` namespace, corresponding conditions from ConfigExpression component (`Oro\Component\ConfigExpression\Condition` namespace) should be used instead

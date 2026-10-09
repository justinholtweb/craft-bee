<?php

namespace justinholtweb\bee\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element as ElementInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `BeeRecommendations` — what `beeRecommend`, `beeRelated` and `beeSearch` return.
 *
 * The GraphQL twin of {@see \justinholtweb\bee\models\RecommendationSet}. `elements` is Craft's own
 * `ElementInterface`, so a headless front end asks for fields with the inline fragments it already
 * uses everywhere else (`... on products_Product { title url }`), and `itemIds` lines up with it
 * one-for-one so each card can carry the attribution attributes the runtime reads.
 *
 * The resolver hands this type a plain array that has already been filtered to the elements the
 * schema may read — the fields below only read keys off it.
 */
class RecommendationSet
{
    public const NAME = 'BeeRecommendations';

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::NAME)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::NAME, new ObjectType([
            'name' => self::NAME,
            'description' => 'Recommendations from Recombee, resolved into Craft elements.',
            'fields' => static fn() => [
                'recommId' => [
                    'type' => Type::string(),
                    'description' => 'Recombee’s ID for this recommendation. Send it with the interaction that follows a click (`data-bee-recomm-id`) so Recombee can learn from it.',
                ],
                'scenario' => [
                    'type' => Type::string(),
                    'description' => 'The scenario the request was made under.',
                ],
                'kind' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`user`, `item` or `search`.',
                ],
                'abGroup' => [
                    'type' => Type::string(),
                    'description' => 'The A/B group Recombee put this request in, when one was returned.',
                ],
                'failed' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'True when Bee fell back to an empty set — Recombee was unreachable, there was no visitor to personalise for, or the edition or schema does not allow the request.',
                ],
                'itemIds' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => 'The Recombee item IDs, in the same order as `elements`.',
                ],
                'elements' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(ElementInterface::getType()))),
                    'description' => 'The recommended elements in Recombee’s order. Anything the schema cannot read, or that is no longer live, is left out.',
                ],
            ],
            'resolveField' => static fn(array $source, array $args, mixed $context, \GraphQL\Type\Definition\ResolveInfo $info) => $source[$info->fieldName] ?? null,
        ]));
    }
}

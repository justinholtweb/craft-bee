<?php

namespace justinholtweb\bee\gql\queries;

use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\bee\gql\resolvers\Recommendations as RecommendationsResolver;
use justinholtweb\bee\gql\types\RecommendationSet;
use justinholtweb\bee\Plugin;

/**
 * `beeRecommend`, `beeRelated` and `beeSearch`. Pro.
 *
 * What the arguments leave out matters as much as what they take:
 *
 *   - **No `userId`.** The visitor is whoever the request's session or Bee's guest cookie says it
 *     is, exactly as for `craft.bee.recommend()`. A token is shared by every caller of a public
 *     schema, so an argument naming the user would let anyone read a stranger's recommendations.
 *   - **No ReQL `filter`, `booster` or `logic`.** ReQL runs inside Recombee, so a filter from the
 *     caller is a way to enumerate the catalog past whatever the site means to show. Templates still
 *     have the whole ReQL surface; a query string from the internet does not.
 */
class Bee extends Query
{
    /**
     * The schema component. Granting it lets a token ask for recommendations; which elements come
     * back is still limited by the sections, groups, volumes and product types the schema can read.
     */
    public const SCOPE = 'bee.recommendations';

    /** The most a single query may ask for, matching the public `bee/recommend` endpoint. */
    public const MAX_COUNT = 100;

    public static function getQueries(bool $checkToken = true): array
    {
        if (!Plugin::getInstance()->isPro()) {
            return [];
        }

        if ($checkToken && !GqlHelper::canSchema(self::SCOPE)) {
            return [];
        }

        $common = [
            'count' => [
                'name' => 'count',
                'type' => Type::int(),
                'description' => 'How many items to return, 1–' . self::MAX_COUNT . '. Defaults to the plugin’s default count.',
            ],
            'scenario' => [
                'name' => 'scenario',
                'type' => Type::string(),
                'description' => 'The Recombee scenario, so each placement can be tuned and reported on separately.',
            ],
            'site' => [
                'name' => 'site',
                'type' => Type::string(),
                'description' => 'The handle of the site to resolve elements in. Defaults to the current site.',
            ],
        ];

        return [
            'beeRecommend' => [
                'type' => Type::nonNull(RecommendationSet::getType()),
                'args' => $common,
                'resolve' => RecommendationsResolver::class . '::recommend',
                'description' => 'Personalised recommendations for the visitor making the request. Empty when there is no visitor Bee may track.',
            ],
            'beeRelated' => [
                'type' => Type::nonNull(RecommendationSet::getType()),
                'args' => [
                    'elementId' => [
                        'name' => 'elementId',
                        'type' => Type::int(),
                        'description' => 'The element to find related items for.',
                    ],
                    'itemId' => [
                        'name' => 'itemId',
                        'type' => Type::string(),
                        'description' => 'Or its Recombee item ID, e.g. `p42`.',
                    ],
                ] + $common,
                'resolve' => RecommendationsResolver::class . '::related',
                'description' => 'Items related to an element — “more like this”, “customers also bought” — personalised when there is a visitor.',
            ],
            'beeSearch' => [
                'type' => Type::nonNull(RecommendationSet::getType()),
                'args' => [
                    'query' => [
                        'name' => 'query',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'What the visitor searched for.',
                    ],
                ] + $common,
                'resolve' => RecommendationsResolver::class . '::search',
                'description' => 'Recombee’s search, personalised to the visitor when there is one.',
            ],
        ];
    }
}

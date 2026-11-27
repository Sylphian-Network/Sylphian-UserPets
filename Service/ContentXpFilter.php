<?php

namespace Sylphian\UserPets\Service;

use XF\Entity\Post;
use XF\Entity\Thread;

class ContentXpFilter
{
	protected static ?array $nodeBounds = null;

	/**
	 * @return array{nodeIds:array<int,int>,prefixIds:array<int,int>,threadTypes:array<int,string>}
	 */
	public static function getCriteriaFromOptions(bool $forPosts): array
	{
		$nodeOption = \XF::options()->{$forPosts ? 'sylphian_userpets_pst_xp_nodes' : 'sylphian_userpets_thr_xp_nodes'};
		$prefixOption = \XF::options()->{$forPosts ? 'sylphian_userpets_pst_xp_pfx' : 'sylphian_userpets_thr_xp_pfx'};
		$threadTypeOption = \XF::options()->{$forPosts ? 'sylphian_userpets_pst_xp_types' : 'sylphian_userpets_thr_xp_types'};

		return [
			'nodeIds' => self::parseIntList($nodeOption),
			'prefixIds' => self::parseIntList($prefixOption),
			'threadTypes' => self::parseStringList($threadTypeOption),
		];
	}

	/**
	 * @param array<int,int> $selectedNodeIds
	 * @return array<int,int>
	 */
	public static function expandSelectedNodeIds(array $selectedNodeIds): array
	{
		if (!$selectedNodeIds)
		{
			return [];
		}

		$bounds = self::getNodeBounds();
		$expandedNodeIds = [];

		foreach ($bounds AS $nodeId => $current)
		{
			foreach ($selectedNodeIds AS $selectedNodeId)
			{
				$selected = $bounds[$selectedNodeId] ?? null;
				if (!$selected)
				{
					continue;
				}

				if ($current['lft'] >= $selected['lft'] && $current['rgt'] <= $selected['rgt'])
				{
					$expandedNodeIds[] = (int) $nodeId;
					break;
				}
			}
		}

		return array_values(array_unique($expandedNodeIds));
	}

	public static function matchesThread(Thread $thread): bool
	{
		$criteria = self::getCriteriaFromOptions(false);
		return self::matchesThreadEntity($thread, $criteria['nodeIds'], $criteria['prefixIds'], $criteria['threadTypes']);
	}

	public static function matchesPost(Post $post): bool
	{
		/** @var Thread|null $thread */
		$thread = $post->Thread;
		if (!$thread)
		{
			return false;
		}

		$criteria = self::getCriteriaFromOptions(true);
		return self::matchesThreadEntity($thread, $criteria['nodeIds'], $criteria['prefixIds'], $criteria['threadTypes']);
	}

	/**
	 * @param array<int,int> $nodeIds
	 * @param array<int,int> $prefixIds
	 * @param array<int,string> $threadTypes
	 */
	protected static function matchesThreadEntity(Thread $thread, array $nodeIds, array $prefixIds, array $threadTypes): bool
	{
		if (!self::matchesNodeFilter((int) $thread->node_id, $nodeIds))
		{
			return false;
		}

		if ($prefixIds && !in_array((int) $thread->prefix_id, $prefixIds, true))
		{
			return false;
		}

		$discussionType = strtolower((string) ($thread->discussion_type ?? 'discussion'));
		if ($threadTypes && !in_array($discussionType, $threadTypes, true))
		{
			return false;
		}

		return true;
	}

	/**
	 * @param array<int,int> $selectedNodeIds
	 */
	protected static function matchesNodeFilter(int $threadNodeId, array $selectedNodeIds): bool
	{
		if (!$selectedNodeIds)
		{
			return true;
		}

		if (in_array($threadNodeId, $selectedNodeIds, true))
		{
			return true;
		}

		$bounds = self::getNodeBounds();
		$current = $bounds[$threadNodeId] ?? null;
		if (!$current)
		{
			return false;
		}

		foreach ($selectedNodeIds AS $selectedNodeId)
		{
			$selected = $bounds[$selectedNodeId] ?? null;
			if (!$selected)
			{
				continue;
			}

			if ($current['lft'] >= $selected['lft'] && $current['rgt'] <= $selected['rgt'])
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int,array{lft:int,rgt:int}>
	 */
	protected static function getNodeBounds(): array
	{
		if (self::$nodeBounds !== null)
		{
			return self::$nodeBounds;
		}

		$bounds = [];
		$nodes = \XF::finder('XF:Node')
			->fetch();

		foreach ($nodes AS $node)
		{
			$bounds[(int) $node->node_id] = [
				'lft' => (int) $node->lft,
				'rgt' => (int) $node->rgt,
			];
		}

		self::$nodeBounds = $bounds;
		return self::$nodeBounds;
	}

	/**
	 * @return array<int,int>
	 */
	protected static function parseIntList($value): array
	{
		if (is_array($value))
		{
			$parsed = [];

			foreach ($value AS $part)
			{
				if (!is_numeric($part))
				{
					continue;
				}

				$int = (int) $part;
				if ($int >= 0)
				{
					$parsed[] = $int;
				}
			}

			return array_values(array_unique($parsed));
		}

		$value = (string) $value;
		$parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$parsed = [];

		foreach ($parts AS $part)
		{
			if (!is_numeric($part))
			{
				continue;
			}

			$int = (int) $part;
			if ($int >= 0)
			{
				$parsed[] = $int;
			}
		}

		return array_values(array_unique($parsed));
	}

	/**
	 * @return array<int,string>
	 */
	protected static function parseStringList($value): array
	{
		if (is_array($value))
		{
			$parsed = [];

			foreach ($value AS $part)
			{
				$part = strtolower(trim((string) $part));
				if ($part !== '')
				{
					$parsed[] = $part;
				}
			}

			return array_values(array_unique($parsed));
		}

		$value = (string) $value;
		$parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$parsed = [];

		foreach ($parts AS $part)
		{
			$part = strtolower(trim($part));
			if ($part !== '')
			{
				$parsed[] = $part;
			}
		}

		return array_values(array_unique($parsed));
	}
}

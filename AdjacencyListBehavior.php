<?php
/**
 * @link https://github.com/paulzi/yii2-adjacency-list
 * @copyright Copyright (c) 2015 PaulZi <pavel.zimakoff@gmail.com>
 * @license MIT (https://github.com/paulzi/yii2-adjacency-list/blob/master/LICENSE)
 */

namespace paulzi\adjacencyList;

use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\NotSupportedException;
use yii\db\ActiveRecord;
use yii\db\Query;
use paulzi\sortable\SortableBehavior;


/**
 * Adjacency List Behavior for Yii2
 * @author PaulZi <pavel.zimakoff@gmail.com>
 *
 * @property ActiveRecord $owner
 */
class AdjacencyListBehavior extends Behavior
{
    const OPERATION_MAKE_ROOT       = 1;
    const OPERATION_PREPEND_TO      = 2;
    const OPERATION_APPEND_TO       = 3;
    const OPERATION_INSERT_BEFORE   = 4;
    const OPERATION_INSERT_AFTER    = 5;
    const OPERATION_DELETE_ALL      = 6;
    
    
    public $multiTree = false;
    
    
    public $treeAttribute = 'tree';
    
    
    public $idAttribute = 'id';

    /**
     * @var string
     */
    public $parentAttribute = 'parent_id';

    /**
     * @var array|false SortableBehavior config
     */
    public $sortable = [];

    /**
     * @var bool
     */
    public $checkLoop = false;

    /**
     * @var int
     */
    public $parentsJoinLevels = 3;

    /**
     * @var int
     */
    public $childrenJoinLevels = 3;

    /**
     * @var bool
     */
    protected $operation;

    /**
     * @var ActiveRecord|self|null
     */
    protected $node;

    /**
     * @var SortableBehavior
     */
    protected $behavior;

    /**
     * @var ActiveRecord[]
     */
    private $_parentsOrdered;

    /**
     * @var array
     */
    private $_parentsIds;

    /**
     * @var array
     */
    private $_childrenIds;


    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_INSERT   => 'beforeSave',
            ActiveRecord::EVENT_AFTER_INSERT    => 'afterSave',
            ActiveRecord::EVENT_BEFORE_UPDATE   => 'beforeSave',
            ActiveRecord::EVENT_AFTER_UPDATE    => 'afterSave',
            ActiveRecord::EVENT_BEFORE_DELETE   => 'beforeDelete',
            ActiveRecord::EVENT_AFTER_DELETE    => 'afterDelete',
        ];
    }

    /**
     * @param ActiveRecord $owner
     */
    public function attach($owner)
    {
        parent::attach($owner);
        if ($this->sortable !== false) {
            $this->behavior = Yii::createObject(array_merge(
                [
                    'class'         => SortableBehavior::className(),
                    'query'         => [$this->parentAttribute],
                ],
                $this->sortable
            ));
            $owner->attachBehavior('adjacency-list-sortable', $this->behavior);
        }
    }

    /**
     * @param int|null $depth
     * @return \yii\db\ActiveQuery
     * @throws Exception
     */
    public function getParents($depth = null)
    {
        $tableName = $this->owner->tableName();
        $ids = $this->getParentsIds($tree, $depth);
        $query = $this->owner->find();
        if ($this->multiTree === true) {
            $tree = $this->owner->getAttribute($this->treeAttribute);
            $query->andWhere(["{$tableName}.[[" . $this->treeAttribute . "]]" => $tree]);
        }
        $query->andWhere(["{$tableName}.[[" . $this->idAttribute . "]]" => $ids]);
        $query->multiple = true;
        return $query;
    }

    /**
     * @param int|null $depth
     * @return ActiveRecord[]
     * @throws Exception
     */
    public function getParentsOrdered($depth = null)
    {        
        if ($depth === null && $this->_parentsOrdered !== null) {
            return $this->_parentsOrdered;
        }
        $parents = $this->getParents($depth)->all();       
        $ids = array_flip($this->getParentsIds());
        $idAttribute = $this->idAttribute;        
        usort($parents, function($a, $b) use ($ids, $idAttribute) {
            $aIdx = $ids[$a->$idAttribute];
            $bIdx = $ids[$b->$idAttribute];
            if ($aIdx == $bIdx) {
                return 0;
            } else {
                return $aIdx > $bIdx ? -1 : 1;
            }
        });
        if ($depth !== null) {
            $this->_parentsOrdered = $parents;
        }
        return $parents;
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws Exception
     */
    public function getParent()
    {
        if ($this->multiTree === true) 
            $condition[$this->treeAttribute] = $this->owner->getAttribute($this->treeAttribute);

        $condition[$this->idAttribute] = $this->parentAttribute;        
        return $this->owner->hasOne($this->owner->className(), $condition);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getRoot()
    {
        $tableName = $this->owner->tableName();
        $id = $this->getParentsIds();
        $id = $id ? $id[count($id) - 1] : $this->idAttribute;
        
        $query = $this->owner->find();
        
        if ($this->multiTree === true)   
            $query->andWhere(["{$tableName}.[[" . $this->treeAttribute . "]]" => $this->owner->getAttribute($this->treeAttribute)]);        
        
        $query->andWhere(["{$tableName}.[[" . $this->idAttribute . "]]" => $id]);            
        $query->multiple = false;
        return $query;
    }

    /**
     * @param int|null $depth
     * @param bool $andSelf
     * @return \yii\db\ActiveQuery
     */
    public function getDescendants($depth = null, $andSelf = false)
    {
        $tableName = $this->owner->tableName();
        $ids = $this->getDescendantsIds($depth, true);
        if ($andSelf) {
            $ids[] = $this->owner->getAttribute($this->idAttribute);
        }
        $query = $this->owner->find();
        if ($this->multiTree === true)
            $query->andWhere(["{$tableName}.[[" . $this->treeAttribute . "]]" => $this->owner->getAttribute($this->treeAttribute)]);
        $query->andWhere(["{$tableName}.[[" . $this->idAttribute . "]]" => $ids]);
        $query->multiple = true;
        return $query;
    }

    /**
     * @param int|null $depth
     * @return ActiveRecord[]
     * @throws Exception
     */
    public function getDescendantsOrdered($depth = null)
    {
        if ($depth === null) {
            $descendants = $this->owner->descendants;
        } else {
            $descendants = $this->getDescendants($depth)->all();
        }
        $ids = array_flip($this->getDescendantsIds($depth, true));
        $idAttribute = $this->idAttribute;
        usort($descendants, function($a, $b) use ($ids, $idAttribute) {
            $aIdx = $ids[$a->$idAttribute];
            $bIdx = $ids[$b->$idAttribute];
            if ($aIdx == $bIdx) {
                return 0;
            } else {
                return $aIdx > $bIdx ? 1 : -1;
            }
        });
        return $descendants;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getChildren()
    {
        if ($this->multiTree === true) 
            $condition[$this->treeAttribute] = $this->treeAttribute;
            
        $condition[$this->parentAttribute] = $this->idAttribute;
        $result = $this->owner->hasMany($this->owner->className(), $condition);
        if ($this->sortable !== false) {
            $result->orderBy([$this->behavior->sortAttribute => SORT_ASC]);
        }
        return $result;
    }

    /**
     * @param int|null $depth
     * @return \yii\db\ActiveQuery
     */
    public function getLeaves($depth = null)
    {
        $query = $this->getDescendants($depth)
            ->joinWith(['children' => function ($query) {
                /** @var \yii\db\ActiveQuery $query */
                $modelClass = $query->modelClass;
                $query
                    ->from($modelClass::tableName() . ' children')
                    ->orderBy(null);
            }]);
            $query->andWhere(["children.[[{$this->parentAttribute}]]" => null]);
        $query->multiple = true;
        return $query;
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws NotSupportedException
     */
    public function getPrev()
    {
        if ($this->sortable === false) {
            throw new NotSupportedException('prev() not allow if not set sortable');
        }
        $tableName = $this->owner->tableName();
        $query = $this->owner->find();
        
        if ($this->multiTree === true) 
            $query->andWhere(["{$tableName}.[[{$this->treeAttribute}]]" => $this->owner->getAttribute($this->treeAttribute)]);

        $query->andWhere([
            'and',
            ["{$tableName}.[[{$this->parentAttribute}]]" => $this->owner->getAttribute($this->parentAttribute)],
            ['<', "{$tableName}.[[{$this->behavior->sortAttribute}]]", $this->owner->getSortablePosition()],
        ])
        ->orderBy(["{$tableName}.[[{$this->behavior->sortAttribute}]]" => SORT_DESC])
        ->limit(1);
        $query->multiple = false;
        return $query;
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws NotSupportedException
     */
    public function getNext()
    {
        if ($this->sortable === false) {
            throw new NotSupportedException('next() not allow if not set sortable');
        }
        $tableName = $this->owner->tableName();
        $query = $this->owner->find();
        
        if ($this->multiTree === true) 
            $query->andWhere(["{$tableName}.[[{$this->treeAttribute}]]" => $this->owner->getAttribute($this->treeAttribute)]);
            
        $query->andWhere([
            'and',
            ["{$tableName}.[[{$this->parentAttribute}]]" => $this->owner->getAttribute($this->parentAttribute)],
            ['>', "{$tableName}.[[{$this->behavior->sortAttribute}]]", $this->owner->getSortablePosition()],
        ])
        ->orderBy(["{$tableName}.[[{$this->behavior->sortAttribute}]]" => SORT_ASC])
        ->limit(1);
        $query->multiple = false;
        return $query;
    }

    /**
     * @param int|null $depth
     * @param bool $cache
     * @return array
     */
    public function getParentsIds($depth = null, $cache = true)
    {
        if ($cache && $this->_parentsIds !== null) {            
            return $depth === null ? $this->_parentsIds : array_slice($this->_parentsIds, 0, $depth);
        }
        
        $tree = null;
        if ($this->multiTree === true)
            $tree = $this->owner->getAttribute($this->treeAttribute);
            
        $parentId = $this->owner->getAttribute($this->parentAttribute);
        if ($parentId === null) {
            if ($cache) {
                $this->_parentsIds = [];
            }
            return [];
        }
        $result     = [(string)$parentId];
        $tableName  = $this->owner->tableName();        
        $depthCur   = 1;
        while ($parentId !== null && ($depth === null || $depthCur < $depth)) {
            $query = (new Query())
                ->select(["lvl0.[[{$this->parentAttribute}]] AS lvl0"])
                ->from("{$tableName} lvl0");
                if (!is_null($tree)) {                   
                    $query->where([
                        "lvl0.[[{$this->treeAttribute}]]" => $tree,
                        "lvl0.[[{$this->idAttribute}]]" => $parentId
                    ]);
                } else {
                    $query->where(["lvl0.[[{$this->idAttribute}]]" => $parentId]);
                }
                
            for ($i = 0; $i < $this->parentsJoinLevels && ($depth === null || $i + $depthCur + 1 < $depth); $i++) {
                $j = $i + 1;                
                $query->addSelect(["lvl{$j}.[[{$this->parentAttribute}]] as lvl{$j}"]);                
                if ($this->multiTree === true) {
                    $query->leftJoin("{$tableName} lvl{$j}", [
                        'AND',
                        "lvl{$j}.[[{$this->treeAttribute}]] = lvl{$i}.[[{$this->treeAttribute}]]",
                        "lvl{$j}.[[{$this->idAttribute}]] = lvl{$i}.[[{$this->parentAttribute}]]"
                    ]);                            
                } else {
                    $query->leftJoin("{$tableName} lvl{$j}", "lvl{$j}.[[{$this->idAttribute}]] = lvl{$i}.[[{$this->parentAttribute}]]");                         
                }
            }
            if ($parentIds = $query->one($this->owner->getDb())) {               
                foreach ($parentIds as $parentId) {
                    $depthCur++;
                    if ($parentId === null || $parentId === '') {
                        break;
                    }
                    $result[] = $parentId;
                }
            } else {
                $parentId = null;
            }
        }
        if ($cache && $depth === null) {
            $this->_parentsIds = $result;
        }
        
        return $result;
    }

    /**
     * @param int|null $depth
     * @param bool $flat
     * @param bool $cache
     * @return array
     */
    public function getDescendantsIds($depth = null, $flat = false, $cache = true)
    {
        if ($cache && $this->_childrenIds !== null) {
            $result = $depth === null ? $this->_childrenIds : array_slice($this->_childrenIds, 0, $depth);
            return $flat && !empty($result) ? call_user_func_array('array_merge', $result) : $result;
        }
        
        $tree = null;
        if ($this->multiTree === true)
            $tree = $this->owner->getAttribute($this->treeAttribute);
            
        $result       = [];
        $tableName    = $this->owner->tableName();
        $depthCur     = 0;
        $lastLevelIds = [$this->owner->getAttribute($this->idAttribute)];
        while (!empty($lastLevelIds) && ($depth === null || $depthCur < $depth)) {
            $levels = 1;
            $depthCur++;
            $query = (new Query())
                ->select(["lvl0.[[{$this->idAttribute}]] AS lvl0"])
                ->from("{$tableName} lvl0");
                if (!is_null($tree)) {
                    $query->where([
                        "lvl0.[[{$this->treeAttribute}]]" => $tree,
                        "lvl0.[[{$this->parentAttribute}]]" => $lastLevelIds                       
                    ]);
                } else {
                    $query->where(["lvl0.[[{$this->parentAttribute}]]" => $lastLevelIds]);
                }
            if ($this->sortable !== false) {
                $query->orderBy(["lvl0.[[{$this->behavior->sortAttribute}]]" => SORT_ASC]);
            }
            for ($i = 0; $i < $this->childrenJoinLevels && ($depth === null || $i + $depthCur + 1 < $depth); $i++) {
                $depthCur++;
                $levels++;
                $j = $i + 1;
                $query->addSelect(["lvl{$j}.[[{$this->idAttribute}]] as lvl{$j}"]);
                if ($this->multiTree === true) {
                    $query->leftJoin("{$tableName} lvl{$j}", [
                        'and',
                        "lvl{$j}.[[{$this->treeAttribute}]] = lvl{$i}.[[{$this->treeAttribute}]]",
                        "lvl{$j}.[[{$this->parentAttribute}]] = lvl{$i}.[[{$this->idAttribute}]]",
                        ['is not', "lvl{$i}.[[{$this->idAttribute}]]", null],
                    ]);
                } else  {
                    $query->leftJoin("{$tableName} lvl{$j}", [
                        'and',
                        "lvl{$j}.[[{$this->parentAttribute}]] = lvl{$i}.[[{$this->idAttribute}]]",
                        ['is not', "lvl{$i}.[[{$this->idAttribute}]]", null],
                    ]);
                }
               
                if ($this->sortable !== false) {
                    $query->addOrderBy(["lvl{$j}.[[{$this->behavior->sortAttribute}]]" => SORT_ASC]);
                }
            }
            if ($this->childrenJoinLevels) {
                $columns = [];
                foreach ($query->all($this->owner->getDb()) as $row) {
                    $level = 0;
                    foreach ($row as $id) {
                        if ($id !== null) {
                            $columns[$level][$id] = true;
                        }
                        $level++;
                    }
                }
                for ($i = 0; $i < $levels; $i++) {
                    if (isset($columns[$i])) {
                        $lastLevelIds = array_keys($columns[$i]);
                        $result[]     = $lastLevelIds;
                    } else {
                        $lastLevelIds = [];
                        break;
                    }
                }
            } else {
                $lastLevelIds = $query->column($this->owner->getDb());
                if ($lastLevelIds) {
                    $result[] = $lastLevelIds;
                }
            }
        }
        if ($cache && $depth === null) {
            $this->_childrenIds = $result;
        }
        return $flat && !empty($result) ? call_user_func_array('array_merge', $result) : $result;
    }

    /**
     * Populate children relations for self and all descendants
     *
     * @param int $depth = null
     * @param string|array $with = null
     * @return static
     */
    public function populateTree($depth = null, $with = null)
    {
        /** @var ActiveRecord[]|static[] $nodes */
        $depths = [$this->owner->getAttribute($this->idAttribute) => 0];
        $data = $this->getDescendantsIds($depth);
        foreach ($data as $i => $ids) {
            foreach ($ids as $id) {
                $depths[$id] = $i + 1;
            }
        }
        $query = $this->getDescendants($depth);
        if ($with) {
            $query->with($with);
        }
        $nodes = $query
            ->orderBy($this->sortable !== false ? [$this->behavior->sortAttribute => SORT_ASC] : null)
            ->all();

        $relates = [];
        foreach ($nodes as $node) {
            $key = $node->getAttribute($this->parentAttribute);
            if (!isset($relates[$key])) {
                $relates[$key] = [];
            }
            $relates[$key][] = $node;
        }

        $nodes[] = $this->owner;
        foreach ($nodes as $node) {
            $key = $node->getAttribute($this->idAttribute);
            if (isset($relates[$key])) {
                $node->populateRelation('children', $relates[$key]);
            } elseif ($depth === null || (isset($depths[$node->getAttribute($this->idAttribute)]) && $depths[$node->getAttribute($this->idAttribute)] < $depth)) {
                $node->populateRelation('children', []);
            }
        }

        return $this->owner;
    }

    /**
     * @return bool
     */
    public function isRoot()
    {
        $parent_id = $this->owner->getAttribute($this->parentAttribute);
        return $parent_id === null || $parent_id === '';
    }

    /**
     * @param ActiveRecord $node
     * @return bool
     */
    public function isChildOf($node)
    {
        $ids = $this->getParentsIds();
        return in_array($node->getAttribute($this->idAttribute), $ids);
    }

    /**
     * @return bool
     */
    public function isLeaf()
    {
        return count($this->owner->children) === 0;
    }

    /**
     * @return ActiveRecord
     */
    public function makeRoot($tree = null)
    {
        $this->operation = self::OPERATION_MAKE_ROOT;
        if ($this->multiTree === true && !is_null($tree)) {
            $this->owner->setAttribute($this->treeAttribute, $tree);
        }
        return $this->owner;
    }

    /**
     * @param ActiveRecord $node
     * @return ActiveRecord
     */
    public function prependTo($node)
    {
        $this->operation = self::OPERATION_PREPEND_TO;
        $this->node = $node;
        return $this->owner;
    }

    /**
     * @param ActiveRecord $node
     * @return ActiveRecord
     */
    public function appendTo($node)
    {
        $this->operation = self::OPERATION_APPEND_TO;
        $this->node = $node;
        return $this->owner;
    }

    /**
     * @param ActiveRecord $node
     * @return ActiveRecord
     */
    public function insertBefore($node)
    {
        $this->operation = self::OPERATION_INSERT_BEFORE;
        $this->node = $node;
        return $this->owner;
    }

    /**
     * @param ActiveRecord $node
     * @return ActiveRecord
     */
    public function insertAfter($node)
    {
        $this->operation = self::OPERATION_INSERT_AFTER;
        $this->node = $node;
        return $this->owner;
    }

    /**
     * Need for paulzi/auto-tree
     */
    public function preDeleteWithChildren()
    {
        $this->operation = self::OPERATION_DELETE_ALL;
    }

    /**
     * @return bool|int
     * @throws \Exception
     * @throws \yii\db\Exception
     */
    public function deleteWithChildren()
    {
        $this->operation = self::OPERATION_DELETE_ALL;
        if (!$this->owner->isTransactional(ActiveRecord::OP_DELETE)) {
            $transaction = $this->owner->getDb()->beginTransaction();
            try {
                $result = $this->deleteWithChildrenInternal();
                if ($result === false) {
                    $transaction->rollBack();
                } else {
                    $transaction->commit();
                }
                return $result;
            } catch (\Exception $e) {
                $transaction->rollBack();
                throw $e;
            }
        } else {
            $result = $this->deleteWithChildrenInternal();
        }
        return $result;
    }

    /**
     * @param bool $middle
     * @return int
     */
    public function reorderChildren($middle = true)
    {
        /** @var ActiveRecord|SortableBehavior $item */
        $item = $this->owner->children[0];
        if ($item) {
            return $item->reorder($middle);
        } else {
            return 0;
        }
    }

    /**
     * @throws Exception
     * @throws NotSupportedException
     */
    public function beforeSave()
    {
        if ($this->node !== null && !$this->node->getIsNewRecord()) {
            $this->node->refresh();
        }
        switch ($this->operation) {
            case self::OPERATION_MAKE_ROOT:
                $this->owner->setAttribute($this->parentAttribute, null);
                if ($this->sortable !== false) {
                    $this->owner->setAttribute($this->behavior->sortAttribute, 0);
                }
                break;

            case self::OPERATION_PREPEND_TO:
                $this->insertIntoInternal(false);
                break;

            case self::OPERATION_APPEND_TO:
                $this->insertIntoInternal(true);
                break;

            case self::OPERATION_INSERT_BEFORE:
                $this->insertNearInternal(false);
                break;

            case self::OPERATION_INSERT_AFTER:
                $this->insertNearInternal(true);
                break;

            default:
                if ($this->owner->getIsNewRecord()) {
                    throw new NotSupportedException('Method "' . $this->owner->className() . '::insert" is not supported for inserting new nodes.');
                }
        }
    }

    /**
     *
     */
    public function afterSave()
    {
        $this->operation = null;
        $this->node      = null;
    }

    /**
     * @param \yii\base\ModelEvent $event
     * @throws Exception
     */
    public function beforeDelete($event)
    {
        if ($this->owner->getIsNewRecord()) {
            throw new Exception('Can not delete a node when it is new record.');
        }
        if ($this->isRoot() && $this->operation !== self::OPERATION_DELETE_ALL) {
            throw new Exception('Method "'. $this->owner->className() . '::delete" is not supported for deleting root nodes.');
        }
        $this->owner->refresh();
    }

    /**
     *
     */
    public function afterDelete()
    {
        if ($this->operation !== static::OPERATION_DELETE_ALL) {
            $this->owner->updateAll(
                [$this->parentAttribute => $this->owner->getAttribute($this->parentAttribute)],
                [$this->parentAttribute => $this->owner->getAttribute($this->idAttribute)]
            );
        }
        $this->operation = null;
    }

    /**
     * @return mixed
     * @throws Exception
     */
    protected function getPrimaryKey()
    {
        return $this->idAttribute;
    }

    /**
     * @param bool $forInsertNear
     * @throws Exception
     */
    protected function checkNode($forInsertNear = false)
    {
        if ($forInsertNear && $this->node->isRoot()) {
            throw new Exception('Can not move a node before/after root.');
        }
        if ($this->node->getIsNewRecord()) {
            throw new Exception('Can not move a node when the target node is new record.');
        }

        if ($this->owner->equals($this->node)) {
            throw new Exception('Can not move a node when the target node is same.');
        }

        if ($this->checkLoop && $this->node->isChildOf($this->owner)) {
            throw new Exception('Can not move a node when the target node is child.');
        }
    }

    /**
     * Append to operation internal handler
     * @param bool $append
     * @throws Exception
     */
    protected function insertIntoInternal($append)
    {
        $this->checkNode(false);
        $this->owner->setAttribute($this->parentAttribute, $this->node->getAttribute($this->idAttribute));
        if ($this->sortable !== false) {
            if ($append) {
                $this->behavior->moveLast();
            } else {
                $this->behavior->moveFirst();
            }
        }
    }

    /**
     * Insert operation internal handler
     * @param bool $forward
     * @throws Exception
     */
    protected function insertNearInternal($forward)
    {
        $this->checkNode(true);
        $this->owner->setAttribute($this->parentAttribute, $this->node->getAttribute($this->parentAttribute));
        if ($this->sortable !== false) {
            if ($forward) {
                $this->behavior->moveAfter($this->node);
            } else {
                $this->behavior->moveBefore($this->node);
            }
        }
    }

    /**
     * @return int
     */
    protected function deleteWithChildrenInternal()
    {
        if (!$this->owner->beforeDelete()) {
            return false;
        }
        $ids = $this->getDescendantsIds(null, true);
        $ids[] = $this->owner->getAttribute($this->idAttribute);
        $result = $this->owner->deleteAll([$this->idAttribute => $ids]);
        $this->owner->setOldAttributes(null);
        $this->owner->afterDelete();
        return $result;
    }
    
}

<?php namespace October\Rain\Database\Behaviors;

use Exception;
use October\Rain\Database\ModelBehavior;
use Illuminate\Database\Eloquent\Collection;

/**
 *
 * DEPRECATED WARNING: This class is deprecated and should be deleted
 * if the current year is equal to or greater than 2015.
 * 
 * @todo Delete this file if year >= 2015.
 *
 * See trait: October\Rain\Database\Traits\NestedTree
 *
 */

class NestedSetModel extends ModelBehavior
{

    /**
     * @var string The database column that identifies the parent.
     */
    protected $parentColumn = 'parent_id';

    /**
     * @var string The database column that identifies the left alignment.
     */
    protected $leftColumn = 'nest_left';

    /**
     * @var string The database column that identifies the right alignment.
     */
    protected $rightColumn = 'nest_right';

    /**
     * @var string The database column that identifies the nesting depth.
     */
    protected $depthColumn = 'nest_depth';

    /**
     * @var int Indicates if the model should be aligned to new parent.
     */
    protected $moveToNewParentId = null;

    /*
     * Constructor
     */
    public function __construct($model)
    {
        parent::__construct($model);

        /*
         * Define relationships
         */
        $model->hasMany['children'] = [
            get_class($model),
            'primaryKey' => $this->getParentColumnName(),
            'order' => $this->getLeftColumnName()
        ];

        $model->belongsTo['parent'] = [
            get_class($model),
            'foreignKey' => $this->getParentColumnName()
        ];

        /*
         * Model property overrides
         */
        if (isset($this->model->nestedSetModelParentColumn))
            $this->parentColumn = $this->model->nestedSetModelParentColumn;

        if (isset($this->model->nestedSetModelLeftColumn))
            $this->leftColumn = $this->model->nestedSetModelLeftColumn;

        if (isset($this->model->nestedSetModelRightColumn))
            $this->rightColumn = $this->model->nestedSetModelRightColumn;

        if (isset($this->model->nestedSetModelDepthColumn))
            $this->depthColumn = $this->model->nestedSetModelDepthColumn;

        /*
         * Bind events
         */
        $model->bindEvent('model.beforeCreate', function() {
            $this->setDefaultLeftAndRight();
        });

        $model->bindEvent('model.beforeSave', function() {
            $this->storeNewParent();
        });

        $model->bindEvent('model.afterSave', function() {
            $this->moveToNewParent();
            $this->setDepth();
        });

        $model->bindEvent('model.beforeDelete', function() {
            $this->deleteDescendants();
        });
    }

    /**
     * Handle if the parent column is modified so it can be realigned.
     * @return void
     */
    public function storeNewParent()
    {
        $parentColumn = $this->getParentColumnName();
        $isDirty = $this->model->isDirty($parentColumn);

        // Parent is not set or unchanged
        if (!$isDirty) {
            $this->moveToNewParentId = false;
        }
        // Created as a root node
        elseif (!$this->model->exists && !$this->getParentId()) {
            $this->moveToNewParentId = false;
        }
        // Parent has been set
        else {
            $this->moveToNewParentId = $this->getParentId();
        }
    }

    /**
     * If the parent identifier is dirty, realign the nesting.
     * @return void
     */
    public function moveToNewParent()
    {
        $parentId = $this->moveToNewParentId;

        if ($parentId === null) {
            $this->makeRoot();
        }
        elseif ($parentId !== false) {
            $this->makeChildOf($parentId);
        }
    }

    /**
     * Get a new query builder for the node's model.
     * @param  bool  $excludeDeleted
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function newNestedSetQuery($excludeDeleted = true)
    {
        return $this->model->newQuery($excludeDeleted)->orderByNested();
    }

    /**
     * Deletes a branch off the tree, shifting all the elements on the right
     * back to the left so the counts work.
     * @return void
     */
    public function deleteDescendants()
    {
        if ($this->getRight() === null || $this->getLeft() === null)
            return;

        $this->model->getConnection()->transaction(function() {
            $this->model->reload();

            $leftCol = $this->getLeftColumnName();
            $rightCol = $this->getRightColumnName();
            $left = $this->getLeft();
            $right = $this->getRight();

            /*
             * Delete children
             */
            $this->newNestedSetQuery()
                ->where($leftCol, '>', $left)
                ->where($rightCol, '<', $right)
                ->delete()
            ;

            /*
             * Update left and right indexes for the remaining nodes
             */
            $diff = $right - $left + 1;

            $this->newNestedSetQuery()
                ->where($leftCol, '>', $right)
                ->decrement($leftCol, $diff)
            ;

            $this->newNestedSetQuery()
                ->where($rightCol, '>', $right)
                ->decrement($rightCol, $diff)
            ;
        });
    }

    //
    // Alignment
    //

    /**
     * Make this model a root node.
     * @return \Model
     */
    public function makeRoot()
    {
        return $this->moveAfter($this->getRoot());
    }

    /**
     * Make model node a child of specified node.
     * @return \Model
     */
    public function makeChildOf($node)
    {
        return $this->moveTo($node, 'child');
    }

    /**
     * Find the left sibling and move to left of it.
     * @return \Model
     */
    public function moveLeft()
    {
        return $this->moveBefore($this->getLeftSibling());
    }

    /**
     * Find the right sibling and move to the right of it.
     * @return \Model
     */
    public function moveRight()
    {
        return $this->moveAfter($this->getRightSibling());
    }

    /**
     * Move to the model to before (left) specified node.
     * @return \Model
     */
    public function moveBefore($node)
    {
        return $this->moveTo($node, 'left');
    }

    /**
     * Move to the model to after (right) a specified node.
     * @return \Model
     */
    public function moveAfter($node)
    {
        return $this->moveTo($node, 'right');
    }

    //
    // Checkers
    //

    /**
     * Returns true if this is a root node.
     * @return boolean
     */
    public function isRoot()
    {
        return $this->getParentId() === null;
    }

    /**
     * Returns true if this is a child node.
     * @return boolean
     */
    public function isChild()
    {
        return !$this->isRoot();
    }

    /**
     * Returns true if this is a leaf node (end of a branch).
     * @return boolean
     */
    public function isLeaf()
    {
        return $this->model->exists && ($this->getRight() - $this->getLeft() == 1);
    }

    /**
     * Checks if the supplied node is inside the subtree of this model.
     * @param \Model
     * @return boolean
     */
    public function isInsideSubtree($node)
    {
        return (
            $this->getLeft() >= $node->getLeft() &&
            $this->getLeft() <= $node->getRight() &&
            $this->getRight() >= $node->getLeft() &&
            $this->getRight() <= $node->getRight()
        );
    }

    /**
     * Returns true if node is a descendant.
     *
     * @param NestedSet
     * @return boolean
     */
    public function isDescendantOf($other)
    {
        return ($this->getLeft() > $other->getLeft() && $this->getLeft() < $other->getRight());
    }

    //
    // Scopes
    //

    /**
     * Query scope which extracts a certain node object from the current query expression.
     * @return \Illuminate\Database\Query\Builder
     */
    public function scopeWithoutNode($query, $node)
    {
        return $query->where($node->getKeyName(), '!=', $node->getKey());
    }

    /**
     * Extracts current node (self) from current query expression.
     * @return \Illuminate\Database\Query\Builder
     */
    public function scopeWithoutSelf($query)
    {
        return $this->scopeWithoutNode($query, $this->model);
    }

    /**
     * Extracts first root (from the current node context) from current query expression.
     * @return \Illuminate\Database\Query\Builder
     */
    public function scopeWithoutRoot($query)
    {
        return $this->scopeWithoutNode($query, $this->getRoot());
    }

    /**
     * Set of all children & nested children.
     * @return \Illuminate\Database\Query\Builder
     */
    public function scopeAllChildren($query, $includeSelf = false)
    {
        $query
            ->where($this->getLeftColumnName(), '>=', $this->getLeft())
            ->where($this->getLeftColumnName(), '<', $this->getRight())
        ;

        if ($includeSelf) return $query;
        else return $query->withoutSelf();
    }

    /**
     * Returns a prepared query with all parents up the tree.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeParents($query, $includeSelf = false)
    {
        $query
            ->where($this->getLeftColumnName(), '<=', $this->getLeft())
            ->where($this->getRightColumnName(), '>=', $this->getRight())
        ;

        if ($includeSelf) return $query;
        else return $query->withoutSelf();
    }

    /**
     * Filter targeting all children of the parent, except self.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSiblings($query, $includeSelf = false)
    {
        $query->where($this->getParentColumnName(), $this->getParentId());

        if ($includeSelf) return $query;
        else return $query->withoutSelf();
    }

    /**
     * Returns all final nodes without children.
     * @return \Illuminate\Database\Query\Builder
     */
    public function scopeLeaves($query)
    {
        $grammar = $this->model->getConnection()->getQueryGrammar();

        $rightCol = $grammar->wrap($this->getQualifiedRightColumnName());
        $leftCol = $grammar->wrap($this->getQualifiedLeftColumnName());

        return $query
            ->allChildren()
            ->whereRaw($rightCol . ' - ' . $leftCol . ' = 1')
        ;
    }

    /**
     * Non chaining scope, returns an eager loaded hierarchy tree. Children are
     * eager loaded inside the $model->children relation.
     * @return Collection A collection
     */
    public function scopeGetNested($query)
    {
        return $query->orderByNested()->get()->toNested();
    }

    /**
     * Gets an array with values of a given column. Values are indented according to their depth.
     * @param  string $column Array values
     * @param  string $key    Array keys
     * @param  string $indent Character to indent depth
     * @return array
     */
    public function scopeListsNested($query, $column, $key = null, $indent = '&nbsp;&nbsp;&nbsp;')
    {
        $query = $query->orderByNested();

        $columns = [$this->getDepthColumnName(), $column];
        if ($key !== null)
            $columns[] = $key;

        $results = new Collection($query->getQuery()->get($columns));
        $values = $results->fetch($columns[1])->all();
        $indentation = $results->fetch($columns[0])->all();

        foreach ($values as $_key => $value) {
            $values[$_key] = str_repeat($indent, $indentation[$_key]) . $value;
        }

        if ($key !== null && count($results) > 0) {
            $keys = $results->fetch($key)->all();

            return array_combine($keys, $values);
        }

        return $values;
    }

    /**
     * Applies the default nested ordering, by the left column.
     */
    public function scopeOrderByNested($query)
    {
        return $query->orderBy($this->getLeftColumnName());
    }

    //
    // Getters
    //

    /**
     * Returns all nodes and children.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getAll($columns = ['*'])
    {
        return $this->newNestedSetQuery()->get($columns);
    }

    /**
     * Returns the root node starting from the current node.
     * @return \Model
     */
    public function getRoot()
    {
        if ($this->model->exists) {
            return $this->newNestedSetQuery()->parents(true)
                ->where(function($query){
                    $query->whereNull($this->getParentColumnName());
                    $query->orWhere($this->getParentColumnName(), 0);
                })
                ->first()
            ;
        }
        else {
            $parentId = $this->getParentId();

            if ($parentId !== null && ($currentParent = $this->model->newQuery()->find($parentId))) {
                return $currentParent->getRoot();
            }
            else {
                return $this->model;
            }
        }
    }

    /**
     * Returns a list of all root nodes, without eager loading
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getAllRoot()
    {
        return $this->newNestedSetQuery()
            ->where(function($query){
                $query->whereNull($this->getParentColumnName());
                $query->orWhere($this->getParentColumnName(), 0);
            })
            ->get()
        ;
    }

    /**
     * Returns a list of all root nodes, with children eager loaded.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getEagerRoot()
    {
        return $this->newNestedSetQuery()->getNested();
    }

    /**
     * Returns an array column/key pair of all root nodes, with children eager loaded.
     * @return array
     */
    public function getRootList($column, $key = null, $indent = '&nbsp;&nbsp;&nbsp;')
    {
        return $this->newNestedSetQuery()->listsNested($column, $key, $indent);
    }

    /**
     * The direct parent node.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getParent()
    {
        return $this->model->parent()->get();
    }

    /**
     * Returns all parents up the tree.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getParents()
    {
        return $this->newNestedSetQuery()->parents()->get();
    }

    /**
     * Returns all parents up the tree and self.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getParentsAndSelf()
    {
        return $this->newNestedSetQuery()->parents(true)->get();
    }

    /**
     * Returns direct child nodes.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getChildren()
    {
        return $this->model->children;
    }

    /**
     * Returns direct child nodes, with ->children eager loaded.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getEagerChildren()
    {
        return $this->newNestedSetQuery()->allChildren()->getNested();
    }

    /**
     * Returns all children down the tree.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getAllChildren()
    {
        return $this->newNestedSetQuery()->allChildren()->get();
    }

    /**
     * Returns all children and self.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getAllChildrenAndSelf()
    {
        return $this->newNestedSetQuery()->allChildren(true)->get();
    }

    /**
     * Return all siblings (parent's children).
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getSiblings()
    {
        return $this->newNestedSetQuery()->siblings()->get();
    }

    /**
     * Return all siblings and self.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getSiblingsAndSelf()
    {
        return $this->newNestedSetQuery()->siblings(true)->get();
    }

    /**
     * Returns all final nodes without children.
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getLeaves()
    {
        return $this->newNestedSetQuery()->leaves()->get();
    }

    /**
     * Returns the level of this node in the tree.
     * Root level is 0.
     * @return int
     */
    public function getLevel()
    {
        if ($this->getParentId() === null)
            return 0;

        return $this->newNestedSetQuery()->parents()->count();
    }

    /**
     * Returns number of all children below it.
     * @return int
     */
    public function getChildCount()
    {
        return ($this->getRight() - $this->getLeft() - 1) / 2;
    }

    //
    // Setters
    //

    /**
     * Sets the depth attribute
     * @return \Model
     */
    public function setDepth()
    {
        $this->model->getConnection()->transaction(function() {
            $this->model->reload();

            $level = $this->getLevel();

            $this->newNestedSetQuery()
                ->where($this->model->getKeyName(), '=', $this->model->getKey())
                ->update([$this->getDepthColumnName() => $level])
            ;

            $this->model->setAttribute($this->getDepthColumnName(), $level);
        });

        return $this->model;
    }

    /**
     * Set defaults for left and right columns.
     * @return void
     */
    public function setDefaultLeftAndRight()
    {
        $highRight = $this->model
            ->newQuery()
            ->orderBy($this->getRightColumnName(), 'desc')
            ->limit(1)
            ->first();

        $maxRight = 0;
        if ($highRight !== null) {
            $maxRight = $highRight->getRight();
        }

        $this->model->setAttribute($this->getLeftColumnName(), $maxRight + 1);
        $this->model->setAttribute($this->getRightColumnName(), $maxRight + 2);
    }

    //
    // Column getters
    //

    /**
     * Get parent column name.
     * @return string
     */
    public function getParentColumnName()
    {
        return $this->parentColumn;
    }

    /**
     * Get fully qualified parent column name.
     * @return string
     */
    public function getQualifiedParentColumnName()
    {
        return $this->model->getTable(). '.' .$this->getParentColumnName();
    }

    /**
     * Get value of the model parent_id column.
     * @return int
     */
    public function getParentId()
    {
        return $this->model->getAttribute($this->getParentColumnName());
    }

    /**
     * Get left column name.
     * @return string
     */
    public function getLeftColumnName()
    {
        return $this->leftColumn;
    }

    /**
     * Get fully qualified left column name.
     * @return string
     */
    public function getQualifiedLeftColumnName()
    {
        return $this->model->getTable() . '.' . $this->getLeftColumnName();
    }

    /**
     * Get value of the left column.
     * @return int
     */
    public function getLeft()
    {
        return $this->model->getAttribute($this->getLeftColumnName());
    }

    /**
     * Get right column name.
     * @return string
     */
    public function getRightColumnName()
    {
        return $this->rightColumn;
    }

    /**
     * Get fully qualified right column name.
     * @return string
     */
    public function getQualifiedRightColumnName()
    {
        return $this->model->getTable() . '.' . $this->getRightColumnName();
    }

    /**
     * Get value of the right column.
     * @return int
     */
    public function getRight()
    {
        return $this->model->getAttribute($this->getRightColumnName());
    }

    /**
     * Get depth column name.
     * @return string
     */
    public function getDepthColumnName()
    {
        return $this->depthColumn;
    }

    /**
     * Get fully qualified depth column name.
     * @return string
     */
    public function getQualifiedDepthColumnName()
    {
        return $this->model->getTable() . '.' . $this->getDepthColumnName();
    }

    /**
     * Get value of the depth column.
     * @return int
     */
    public function getDepth()
    {
        return $this->model->getAttribute($this->getDepthColumnName());
    }

    //
    // Moving
    //

    /**
     * Handler for all node alignments.
     * @param mixed  $target
     * @param string $position
     * @return \Model
     */
    protected function moveTo($target, $position)
    {
        /*
         * Validate target
         */
        if ($target instanceof \October\Rain\Database\Model)
            $target->reload();
        else
            $target = $this->model->newNestedSetQuery()->find($target);

        /*
         * Validate move
         */
        if (!$this->validateMove($this->model, $target, $position))
            return $this->model;

        /*
         * Perform move
         */
        $this->model->getConnection()->transaction(function() use ($target, $position) {
            $this->performMove($this->model, $target, $position);
        });

        /*
         * Reapply alignments
         */
        $target->reload();
        $this->model->setDepth();

        foreach ($this->newNestedSetQuery()->allChildren()->get() as $descendant) {
            $descendant->save();
        }

        $this->model->reload();
        return $this->model;
    }

    /**
     * Executes the SQL query associated with the update of the indexes affected
     * by the move operation.
     * @return int
     */
    protected function performMove($node, $target, $position)
    {
        list($a, $b, $c, $d) = $this->getSortedBoundaries($node, $target, $position);

        $connection = $node->getConnection();
        $grammar = $connection->getQueryGrammar();

        $parentId = ($position == 'child')
            ? $target->getKey()
            : $target->getParentId();

        if ($parentId === null)
            $parentId = 'NULL';

        $currentId = $node->getKey();
        $leftColumn = $node->getLeftColumnName();
        $rightColumn = $node->getRightColumnName();
        $parentColumn = $node->getParentColumnName();
        $wrappedLeft = $grammar->wrap($leftColumn);
        $wrappedRight = $grammar->wrap($rightColumn);
        $wrappedParent = $grammar->wrap($parentColumn);
        $wrappedId = $grammar->wrap($node->getKeyName());

        $leftSql = "CASE
            WHEN $wrappedLeft BETWEEN $a AND $b THEN $wrappedLeft + $d - $b
            WHEN $wrappedLeft BETWEEN $c AND $d THEN $wrappedLeft + $a - $c
            ELSE $wrappedLeft END";

        $rightSql = "CASE
            WHEN $wrappedRight BETWEEN $a AND $b THEN $wrappedRight + $d - $b
            WHEN $wrappedRight BETWEEN $c AND $d THEN $wrappedRight + $a - $c
            ELSE $wrappedRight END";

        $parentSql = "CASE
            WHEN $wrappedId = $currentId THEN $parentId
            ELSE $wrappedParent END";

        $result = $node->newNestedSetQuery()
            ->where(function($query) use ($leftColumn, $rightColumn, $a, $d) {
                $query
                    ->whereBetween($leftColumn, [$a, $d])
                    ->orWhereBetween($rightColumn, [$a, $d])
                ;
            })
            ->update([
                $leftColumn => $connection->raw($leftSql),
                $rightColumn => $connection->raw($rightSql),
                $parentColumn => $connection->raw($parentSql)
            ])
        ;

        return $result;
    }

    /**
     * Validates a proposed move and returns true if changes are needed.
     * @return void
     */
    protected function validateMove($node, $target, $position)
    {
        if (!$node->exists)
            throw new Exception('A new node cannot be moved.');

        if (!in_array($position, ['child', 'left', 'right']))
            throw new Exception(sprintf('Position should be either child, left, right. Supplied position is "%s".', $position));

        if ($target === null) {
            if ($position == 'left' || $position == 'right')
                throw new Exception(sprintf('Cannot resolve target node. This node cannot move any further to the %s.', $position));
            else
                throw new Exception('Cannot resolve target node.');
        }

        if ($node == $target)
            throw new Exception('A node cannot be moved to itself.');

        if ($target->isInsideSubtree($node))
            throw new Exception('A node cannot be moved to a descendant of itself.');

        return !(
            $this->getPrimaryBoundary($node, $target, $position) == $node->getRight() ||
            $this->getPrimaryBoundary($node, $target, $position) == $node->getLeft()
        );
    }

    /**
     * Calculates the boundary.
     * @return int
     */
    protected function getPrimaryBoundary($node, $target, $position)
    {
        $primaryBoundary = null;
        switch ($position) {
            case 'child':
                $primaryBoundary = $target->getRight();
                break;

            case 'left':
                $primaryBoundary = $target->getLeft();
                break;

            case 'right':
                $primaryBoundary = $target->getRight() + 1;
                break;
        }

        return ($primaryBoundary > $node->getRight())
            ? $primaryBoundary - 1
            : $primaryBoundary;
    }

    /**
     * Calculates the other boundary.
     * @return int
     */
    protected function getOtherBoundary($node, $target, $position)
    {
        return ($this->getPrimaryBoundary($node, $target, $position) > $node->getRight())
            ? $node->getRight() + 1
            : $node->getLeft() - 1;
    }

    /**
     * Calculates a sorted boundaries array.
     * @return array
     */
    protected function getSortedBoundaries($node, $target, $position)
    {
        $boundaries = [
            $node->getLeft(),
            $node->getRight(),
            $this->getPrimaryBoundary($node, $target, $position),
            $this->getOtherBoundary($node, $target, $position)
        ];

        sort($boundaries);
        return $boundaries;
    }

}
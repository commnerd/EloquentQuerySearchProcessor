<?php

namespace Tests\Unit;

use Commnerd\QuerySearchProcessor\ConflictingParametersException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Tests\Models\{NonModelClass, Simple};
use Commnerd\QuerySearchProcessor\NonModelException;

class QuerySearchProcessorTest extends TestCase
{
    public function testOnlyUsableOnModels()
    {
        $this->expectException(NonModelException::class);

        $request = new Request();

        NonModelClass::processQuery($request);
    }

    public function testEmptyQuery()
    {
        $request = new Request();

        $this->assertInstanceOf(Builder::class, Simple::processQuery($request));
    }

    public function testLoneWithInclusion()
    {
        $request = new Request([
            '_with' => 'parent',
        ]);

        $this->assertNotEmpty(Simple::processQuery($request)->getEagerLoads()['parent']);
    }

    public function testSimpleOrderQuery()
    {
        $request = new Request([
            '_orderBy' => 'input',
        ]);

        $this->assertEquals(
            'select * from "simples" order by "input" asc',
            Simple::processQuery($request)->toSql()
        );
    }

    public function testGarbageOrderQuery()
    {
        $request = new Request([
            '_orderBy' => 'input',
            '_order' => 'garbage',
        ]);

        $this->assertEquals(
            'select * from "simples" order by "input" asc',
            Simple::processQuery($request)->toSql()
        );
    }

    public function testException()
    {
        $this->expectException(ConflictingParametersException::class);

        $request = new Request([
            '_orderBy' => 'input',
            '_orderRandom' => true,
        ]);

        Simple::processQuery($request)->toSql();
    }

//    public function testRandomOrderingDoesntBlowUp()
//    {
//        $request = new Request([
//            '_orderRandom' => true,
//        ]);
//
//        $this->assert
//        $this->assertEquals(
//            'select * from "simples"',
//            Simple::processQuery($request)->toSql()
//        );
//    }

    public function testDescendingOrderQuery()
    {
        $request = new Request([
            '_orderBy' => 'input',
            '_order' => 'desc',
        ]);

        $this->assertEquals(
            'select * from "simples" order by "input" desc',
            Simple::processQuery($request)->toSql()
        );
    }

    public function testSimpleGenericQuery()
    {
        $request = new Request([
            'input' => 'something',
        ]);

        $this->assertEquals(
            [[
                "type" => "Basic",
                "column" => "input",
                "operator" => "=",
                "value" => "something",
                "boolean" => "and"
            ]],
            Simple::processQuery($request)->getQuery()->wheres
        );
    }

    public function testSimpleNamespacedQuery()
    {
        $request = new Request([
            'simple_input' => 'something',
        ]);

        $this->assertEquals(
            [[
                "type" => "Basic",
                "column" => "input",
                "operator" => "=",
                "value" => "something",
                "boolean" => "and"
            ]],
            Simple::processQuery($request)->getQuery()->wheres
        );
    }

    public function testSimpleGenericLikeQuery()
    {
        $request = new Request([
            'name' => '~something',
        ]);

        $this->assertEquals(
            [[
                "type" => "Basic",
                "column" => "name",
                "operator" => "like",
                "value" => "%something",
                "boolean" => "and"
            ]],
            Simple::processQuery($request)->getQuery()->wheres
        );
    }

    public function testSimpleNamespacedLikeQuery()
    {
        $request = new Request([
            'simple_name' => 'something~',
        ]);

        $this->assertEquals(
            [[
                "type" => "Basic",
                "column" => "name",
                "operator" => "like",
                "value" => "something%",
                "boolean" => "and"
            ]],
            Simple::processQuery($request)->getQuery()->wheres
        );
    }

    public function testGenericQueryWithSingleRelation()
    {
        $request = new Request([
            '_with' => 'parent',
            'name' => 'something~',
        ]);

        $this->assertNotEmpty(Simple::processQuery($request)->getEagerLoads()['parent']);
    }

    public function testGenericQueryWithDoubleRelation()
    {
        $request = new Request([
            '_with' => 'parent.grandparent',
            'name' => 'something~',
        ]);

        $this->assertNotEmpty(Simple::processQuery($request)->getEagerLoads()['parent']);
    }

//    public function testGenericQueryWithParallelRelation()
//    {
//        $request = new Request([
//            '_with' => 'parent,other_parent',
//            'name' => 'something~',
//        ]);
//
//        $expected = 'select "simples"."name" as "simples_name", "simples"."input" as "simples_input", "simples".* from "simples" '.
//            'left join "parent_nodes" as "parent_nodes_1" on "simples"."parent_id" = "parent_nodes_1"."id" '.
//            'left join "other_parent_nodes" as "other_parent_nodes_1" on "simples"."other_parent_id" = "other_parent_nodes_1"."id" '.
//            'where ("simples"."name" like ? or "parent_nodes_1"."name" like ? or "other_parent_nodes_1"."name" like ?)';
//
//        $this->assertEquals(
//            $expected,
//            Simple::processQuery($request)->toSql()
//        );
//    }
//
//    public function testGenericQueryWithParallelHasManyBelongsToRelations()
//    {
//        $request = new Request([
//            '_with' => 'parent.other,other_parent.other',
//            'something' => 'something',
//        ]);
//
//        $expected = 'select "simples"."name" as "simples_name", "simples"."input" as "simples_input", "simples".* from "simples" '.
//            'left join "parent_nodes" as "parent_nodes_1" on "simples"."parent_id" = "parent_nodes_1"."id" '.
//            'right join "another_models" as "another_models_1" on "parent_nodes_1"."parent_node_id" = "another_models_1"."id" '.
//            'left join "other_parent_nodes" as "other_parent_nodes_1" on "simples"."other_parent_id" = "other_parent_nodes_1"."id" '.
//            'left join "another_models" as "another_models_2" on "other_parent_nodes_1"."other_id" = "another_models_2"."id" '.
//            'where ("another_models_1"."something" = ? or "another_models_2"."something" = ? or "another_models_1"."something" = ? or "another_models_2"."something" = ?)';
//
//        $this->assertEquals(
//            $expected,
//            Simple::processQuery($request)->toSql()
//        );
//    }
//
//    public function testHasManyQuery()
//    {
//        $request = new Request([
//            '_with' => 'parent.other',
//            'something' => '~blah'
//        ]);
//
//        $expected = 'select "simples"."name" as "simples_name", "simples"."input" as "simples_input", "simples".* from "simples" '.
//            'left join "parent_nodes" as "parent_nodes_1" on "simples"."parent_id" = "parent_nodes_1"."id" '.
//            'right join "another_models" as "another_models_1" on "parent_nodes_1"."parent_node_id" = "another_models_1"."id" '.
//            'where ("another_models_1"."something" like ?)';
//
//        $this->assertEquals(
//            $expected,
//            Simple::processQuery($request)->toSql()
//        );
//    }
//
//    public function testComplexGenericQueryWithOrdering()
//    {
//        $request = new Request([
//            '_with' => 'parent.grandparent',
//            '_orderBy' => 'due_date',
//            '_order' => 'desc',
//            'simple_completed' => 'false',
//            'name' => '~some~',
//            'due_date' => '~some~',
//        ]);
//
//        $expected = 'select "simples"."name" as "simples_name", "simples"."input" as "simples_input", "simples".* from "simples" '.
//                    'left join "parent_nodes" as "parent_nodes_1" on "simples"."parent_id" = "parent_nodes_1"."id" '.
//                    'left join "grand_parent_nodes" as "grand_parent_nodes_1" on "parent_nodes_1"."grand_parent_node_id" = "grand_parent_nodes_1"."id" '.
//                    'where ("simples"."name" like ? or "parent_nodes_1"."name" like ? or "grand_parent_nodes_1"."name" like ? '.
//                    'or "parent_nodes_1"."due_date" like ?)';
//
//        $this->assertEquals(
//            $expected,
//            Simple::processQuery($request)->toSql()
//        );
//    }
}
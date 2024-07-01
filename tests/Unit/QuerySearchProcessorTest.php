<?php

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
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

        $this->assertEquals('select * from "simples"', Simple::processQuery($request)->toSql());
    }

    public function testSimpleGenericQuery()
    {
        $request = new Request([
            'input' => 'something',
        ]);


        $this->assertEquals(
            'select * from "simples" where "input" = ?',
            Simple::processQuery($request)->toSql()
        );
    }

    public function testSimpleNamespacedQuery()
    {
        $request = new Request([
            'simple_input' => 'something',
        ]);

        $this->assertEquals(
            'select * from "simples" where "input" = ?',
            Simple::processQuery($request)->toSql());
    }

    public function testSimpleGenericLikeQuery()
    {
        $request = new Request([
            'name' => '~something',
        ]);

        $this->assertEquals(
            'select * from "simples" where "name" like ?',
            Simple::processQuery($request)->toSql()
        );
    }

    public function testSimpleNamespacedLikeQuery()
    {
        $request = new Request([
            'simple_name' => 'something~',
        ]);

        $this->assertEquals(
            'select * from "simples" where "name" like ?',
            Simple::processQuery($request)->toSql()
        );
    }

    public function testGenericQueryWithSingleRelation()
    {
        $request = new Request([
            '_with' => 'parent',
            'name' => 'something~',
        ]);

        $expected = 'select * from "simples" left join "parent_nodes" on "simples"."parent_id" = "parent_nodes"."id" '.
                    'where ("simples"."name" like ? or "parent_nodes"."name" like ?)';

        $this->assertEquals(
            $expected,
            Simple::processQuery($request)->toSql()
        );
    }

    public function testGenericQueryWithDoubleRelation()
    {
        $request = new Request([
            '_with' => 'parent.grandparent',
            'name' => 'something~',
        ]);

        $expected = 'select * from "simples" '.
            'left join "parent_nodes" on "simples"."parent_id" = "parent_nodes"."id" '.
            'left join "grand_parent_nodes" on "parent_nodes"."grand_parent_node_id" = "grand_parent_nodes"."id" '.
            'where ("simples"."name" like ? or "parent_nodes"."name" like ? or "grand_parent_nodes"."name" like ?)';

        $this->assertEquals(
            $expected,
            Simple::processQuery($request)->toSql()
        );
    }
}
# QuerySearchProcessor
Craft more complex eloquent searches based on query data passed in from http request parameters

## Overview
The goal of this package is to provide a simple way to craft hierarchical searches over more complicated Eloquent model
relationship hierarchies from your browser, while also staying out of the way of the traditional builder system. In
other words, this package will not expose the builder it's crafted to allow for extending.

## Examples

The following lines will show example http queries and their corresponding generated sql. For this example, we will assume
that our route (/some/endpoint) maps to `return response()->json(Simple::processQuery($query)->get());` where 'Simple' is
a basic Eloquent Model that uses the `QuerySearchProcessor` trait defined in this library.  We will also assume that it
belongs to a `parent` relationship `ParentNode` and that the `ParentNode` class has a relationship belonging to a
`GrandParentNode` class.

The following will work as outlined below:

- https://some_url/some/endpoint
```sql
select * from "endpoints"
```

- https://some_url/some/endpoint?name=test
```sql
select * from "endpoints" where "name" = 'test';
```

- https://some_url/some/endpoint?name=test~
```sql
select * from "endpoints" where "name" like 'test%';
```

- https://some_url/some/endpoint?_with=parent&simple_input=blah&name=~test~
```sql
select * from "endpoints" left join "parent_nodes" on "endpoints"."parent_node_id" = "parent_nodes"."id"
where "simples"."input" = 'blah' and ("simples"."name" like '%test%' and "parent_nodes"."name" like '%test%')
```
In this case, since we have a variable called simple_input, the query recognizes that the top-level
class name for this query is 'Simple', and recognizes the rest of the variable as a namespaced variable.  To
label a where clause for the parent name, see below.

- https://some_url/some/endpoint?_with=parent&simple_parent_name=~blah
```sql
select * from "endpoints" left join "parent_nodes" on "endpoints"."parent_node_id" = "parent_nodes"."id"
where "parent_nodes"."name" = '%blah'
```

- https://some_url/some/endpoint?_with=parent.grandparent&name=~something~

This is where the real power of the app comes...  Since 'name' is not namespaced, it searches for the 'name' variable on
all relationships and writes the following query
```sql
select * from "simples"
left join "parent_nodes" on "simples"."parent_id" = "parent_nodes"."id"
left join "grand_parent_nodes" on "parent_nodes"."grand_parent_node_id" = "grand_parent_nodes"."id"
where ("simples"."name" like ? or "parent_nodes"."name" like ? or "grand_parent_nodes"."name" like ?)
```

For more clarity, check out the tests in tests/Unit/QuerySearchProcessorTest.php.  More will continue to be added, as well
as support for 'Has' functions, which will need to be right-joined.

## Testing
To run test suite, run the following:
```bash
php vendor/bin/phpunit tests/Unit/QuerySearchProcessorTest.php
```
<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * App solely serves APIs via /graphql (no root '/' web page): verify the app
     * boots successfully and the GraphQL schema is valid.
     */
    public function test_the_graphql_endpoint_responds(): void
    {
        $response = $this->postJson('/graphql', ['query' => '{ __typename }']);

        $response->assertOk()->assertJsonPath('data.__typename', 'Query');
    }
}

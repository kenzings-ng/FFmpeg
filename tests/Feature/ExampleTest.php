<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * App chỉ phục vụ API qua /graphql (không có trang '/'): kiểm tra app
     * boot được và schema GraphQL hợp lệ.
     */
    public function test_the_graphql_endpoint_responds(): void
    {
        $response = $this->postJson('/graphql', ['query' => '{ __typename }']);

        $response->assertOk()->assertJsonPath('data.__typename', 'Query');
    }
}

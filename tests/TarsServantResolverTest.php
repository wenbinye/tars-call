<?php

namespace wenbinye\tars\call;

use kuiper\tars\integration\QueryFServant;
use kuiper\tars\server\servant\AdminServant;

class TarsServantResolverTest extends TestCase
{
    public function testAdminObj()
    {
        $resolver = $this->getContainer()->get(TarsServantResolver::class);
        $this->assertEquals(AdminServant::class, $resolver->resolve("winwin.district.AdminObj"));
    }

    public function testTarsServant()
    {
        $resolver = $this->getContainer()->get(TarsServantResolver::class);
        $this->assertEquals(QueryFServant::class, $resolver->resolve("tars.tarsregistry.QueryObj"));
    }

    public function testResolveDistrict()
    {
        $resolver = $this->getContainer()->get(TarsServantResolver::class);
        $resolver = $resolver->withRoutes([
            'tars.tarsregistry.QueryObj@tcp -h localhost -p 17890',
            'winwin.district.AdminObj@tcp -h 127.0.0.1 -p 20106',
        ]);
        $this->assertMatchesRegularExpression('#^wenbinye\\\\tars\\\\call\\\\integration\\\\winwin\\\\district\\\\[a-z]+\\\\DistrictServant$#', $resolver->resolve("winwin.district.DistrictObj"));
    }
}

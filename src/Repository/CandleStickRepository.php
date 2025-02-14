<?php

namespace App\Repository;

use App\Entity\CandleStick;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CandleStick>
 *
 * @method CandleStick|null find($id, $lockMode = null, $lockVersion = null)
 * @method CandleStick|null findOneBy(array $criteria, array $orderBy = null)
 * @method CandleStick[]    findAll()
 * @method CandleStick[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CandleStickRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CandleStick::class);
    }

    // public function getCandleSticksWithHugeGrowthSpike(DateTime $startDate, DateTime $endDate, float $growthPercentage = 1, float $minVolume = 600000)
    // {
    //     return $this->createQueryBuilder('c')
    //         ->where('(c.closePrice / c.openPrice) - 1 >= :growthPercentage')
    //         ->andWhere('c.volume > :minVolume')
    //         ->setParameter('growthPercentage', $growthPercentage)
    //         ->setParameter('minVolume', $minVolume)
    //         ->orderBy('c.date', 'ASC')
    //         ->getQuery()
    //         ->getResult();
    // }

    public function getCandleSticksWithHugeGrowthSpike(
        DateTime $startDate, 
        DateTime $endDate, 
        float $growthPercentage = 1, 
        float $minVolume = 600000
    ) {
        return $this->createQueryBuilder('c')
            ->where('(c.closePrice / c.openPrice) - 1 >= :growthPercentage')
            ->andWhere('c.volume > :minVolume')
            ->andWhere('c.date BETWEEN :startDate AND :endDate')
            ->setParameter('growthPercentage', $growthPercentage)
            ->setParameter('minVolume', $minVolume)
            ->setParameter('startDate', $startDate->format('Y-m-d'))
            ->setParameter('endDate', $endDate->format('Y-m-d'))
            ->orderBy('c.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
}

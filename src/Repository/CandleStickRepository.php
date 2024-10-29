<?php

namespace App\Repository;

use App\Entity\CandleStick;
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

//    /**
//     * @return CandleStick[] Returns an array of CandleStick objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('c')
//            ->andWhere('c.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('c.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?CandleStick
//    {
//        return $this->createQueryBuilder('c')
//            ->andWhere('c.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}

<?php

/**
 * This file is part of the PropelAclBundle package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propel\Bundle\PropelAclBundle\Model\Acl;

use Propel\Bundle\PropelAclBundle\Model\Acl\om\BaseAclClass;
use Symfony\Component\Security\Acl\Model\ObjectIdentityInterface;

class AclClass extends BaseAclClass
{
    /**
     * Return an AclClass for the given ACL ObjectIdentity.
     *
     * If none can be found, a new one will be saved.
     *
     * @param \Symfony\Component\Security\Acl\Model\ObjectIdentityInterface $objectIdentity
     * @param \PropelPDO                                                    $con
     *
     * @return \Propel\Bundle\PropelAclBundle\Model\Acl\AclClass
     */
    public static function fromAclObjectIdentity(ObjectIdentityInterface $objectIdentity, \PropelPDO $con = null)
    {
        $obj = AclClassQuery::create()
            ->filterByType($objectIdentity->getType())
            ->findOneOrCreate($con)
        ;

        if ($obj->isNew()) {
            $obj->save($con);
        }

        return $obj;
    }
}

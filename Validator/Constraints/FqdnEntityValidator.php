<?php

namespace LaxCorp\FqdnValidatorBundle\Validator\Constraints;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use LaxCorp\FqdnValidatorBundle\Validator\FqdnValidator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @inheritdoc
 */
class FqdnEntityValidator extends ConstraintValidator
{

    const MESSAGE_ALREADY_USED = 'This value is already used.';
    const DOMAIN_NOT_FOUND = 'fqdn.dns_user_domain_not_found';
    const CNAME_NOT_EQUAL_CATALOG_CNAME = 'fqdn.user_domain_not_equal_catalog_cname';
    const CATALOG_DOMAIN_NOT_FOUND = 'fqdn.dns_catalog_domain_not_found';
    const PLACE_DOMAIN_PREFIX = 'fqdn.place_domain_prefix';
    const EXCEEDED_SUBDOMAIN_LEVEL = 'fqdn.exceeded_subdomain_level';
    const MESSAGE_NAME_RESERVED = 'fqdn.name_reserved';

    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var array
     */
    private $reservedNames;

    /**
     * @inheritdoc
     */
    public function __construct(ManagerRegistry $registry, ContainerInterface $container, array $reservedNames = [])
    {
        $this->registry      = $registry;
        $this->container     = $container;
        $this->reservedNames = $reservedNames;
    }


    /**
     * @param object     $entity
     * @param Constraint $constraint
     *
     * @throws UnexpectedTypeException
     * @throws ConstraintDefinitionException
     */
    public function validate($entity, Constraint $constraint)
    {

        if (!$constraint instanceof FqdnEntity) {
            throw new UnexpectedTypeException($constraint, __NAMESPACE__ . '\FqdnEntity');
        }

        if (!is_string($constraint->fieldFqdn)) {
            throw new UnexpectedTypeException($constraint->fieldFqdn, 'string');
        }

        if (null !== $constraint->errorPath && !is_string($constraint->errorPath)) {
            throw new UnexpectedTypeException($constraint->errorPath, 'string or null');
        }

        $fieldFqdn = (string)$constraint->fieldFqdn;

        if (!$fieldFqdn) {
            throw new ConstraintDefinitionException('fieldFqdn has to be specified.');
        }

        if ($constraint->em) {
            $em = $this->registry->getManager($constraint->em);

            if (!$em) {
                throw new ConstraintDefinitionException(sprintf(
                    'Object manager "%s" does not exist.',
                    $constraint->em
                ));
            }
        } else {
            $em = $this->registry->getManagerForClass(get_class($entity));

            if (!$em) {
                throw new ConstraintDefinitionException(sprintf(
                    'Unable to find the object manager associated with an entity of class "%s".',
                    get_class($entity)
                ));
            }
        }

        /* @var $class ClassMetadataInfo */
        $class = $em->getClassMetadata(get_class($entity));

        $criteria = [];

        if (!$class->hasField($fieldFqdn) && !$class->hasAssociation($fieldFqdn)) {
            throw new ConstraintDefinitionException(sprintf(
                'The fieldFqdn "%s" is not mapped by Doctrine, so it cannot be validated for uniqueness.',
                $fieldFqdn
            ));
        }

        $fqdnValue = $class->reflFields[$fieldFqdn]->getValue($entity);

        if (empty($fqdnValue)) {
            return;
        }

        $errorMessage = null;

        $catalogCname        = $this->container->getParameter('catalog_cname');
        $catalogDomainSuffix = $this->container->getParameter('catalog_domain_suffix');

        $fqdnValue = mb_strtolower($fqdnValue);

        if ($fqdnValue === $catalogDomainSuffix) {
            $errorMessage = $this::PLACE_DOMAIN_PREFIX;
        }

        $subdomain = str_replace($catalogDomainSuffix, '', $fqdnValue);
        $subdomain = ($subdomain === $fqdnValue) ? false : $subdomain;

        if (!$errorMessage && $subdomain) {
            $subdomains = preg_split('/(\.)/', $subdomain, 2, PREG_SPLIT_NO_EMPTY);
            if (is_iterable($subdomains) && count($subdomains) > 1) {
                $errorMessage = $this::EXCEEDED_SUBDOMAIN_LEVEL;
            }
        }

        if (!$errorMessage && $subdomain) {
            if (is_iterable($this->reservedNames) && in_array($subdomain, $this->reservedNames)) {
                $errorMessage = $this::MESSAGE_NAME_RESERVED;
            }
        }

        $fqdnIp = gethostbyname(idn_to_ascii($fqdnValue, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46));

        if (!$errorMessage && $fqdnIp === $fqdnValue) {
            $errorMessage = $this::DOMAIN_NOT_FOUND;
        }

        $catalogCnameIp = gethostbyname($catalogCname);

        if (!$errorMessage && $catalogCnameIp === $catalogCname) {
            $errorMessage = $this::CATALOG_DOMAIN_NOT_FOUND;
        }

        if (!$errorMessage && $fqdnIp !== $catalogCnameIp) {
            $errorMessage = $this::CNAME_NOT_EQUAL_CATALOG_CNAME;
        }

        if ($errorMessage !== null) {
            $this->context->buildViolation($errorMessage, [
                '%catalog_cname%'         => $catalogCname,
                '%catalog_domain_suffix%' => $catalogDomainSuffix
            ])
                ->atPath($fieldFqdn)
                ->setParameter('{{ value }}', $this->formatWithIdentifiers($em, $class, $fqdnValue))
                ->setInvalidValue($fqdnValue)
                ->addViolation();

            return;
        }

        $fqdnValidator = new FqdnValidator();

        if ($fqdnValue !== null && !$fqdnValidator->validate($fqdnValue, $errorMessage)) {

            $this->context->buildViolation($errorMessage)
                ->atPath($fieldFqdn)
                ->setParameter('{{ value }}', $this->formatWithIdentifiers($em, $class, $fqdnValue))
                ->setInvalidValue($fqdnValue)
                ->addViolation();

            return;
        }

        if ($constraint->ignoreNull && $fqdnValue !== null) {

            $criteria[$fieldFqdn] = $fqdnValue;

            if ($criteria[$fieldFqdn] !== null && $class->hasAssociation($fieldFqdn)) {
                $em->initializeObject($criteria[$fieldFqdn]);
            }
        }

        if (empty($criteria)) {
            return;
        }

        $repository = $em->getRepository(get_class($entity));
        $result     = $repository->{$constraint->repositoryMethod}($criteria);

        if ($result instanceof \IteratorAggregate) {
            $result = $result->getIterator();
        }

        /* If the result is a MongoCursor, it must be advanced to the first
         * element. Rewinding should have no ill effect if $result is another
         * iterator implementation.
         */
        if ($result instanceof \Iterator) {
            $result->rewind();
        } elseif (is_array($result)) {
            reset($result);
        }

        /* If no entity matched the query criteria or a single entity matched,
         * which is the same as the entity being validated, the criteria is
         * unique.
         */
        if (0 === count($result)
            || (1 === count($result)
                && $entity === ($result instanceof \Iterator ? $result->current() : current($result)))) {
            return;
        }

        $errorPath    = null !== $constraint->errorPath ? $constraint->errorPath : $fieldFqdn;
        $invalidValue = isset($criteria[$errorPath]) ? $criteria[$errorPath] : $criteria[$fieldFqdn];

        $this->context->buildViolation($this::MESSAGE_ALREADY_USED)
            ->atPath($errorPath)
            ->setParameter('{{ value }}', $this->formatWithIdentifiers($em, $class, $invalidValue))
            ->setInvalidValue($invalidValue)
            ->setCode(FqdnEntity::NOT_UNIQUE_ERROR)
            ->addViolation();
    }

    /**
     * @param ObjectManager $em
     * @param ClassMetadata $class
     * @param               $value
     *
     * @return string
     */
    private function formatWithIdentifiers(ObjectManager $em, ClassMetadata $class, $value)
    {
        if (!is_object($value) || $value instanceof \DateTimeInterface) {
            return $this->formatValue($value, self::PRETTY_DATE);
        }

        // non unique value is a composite PK
        if ($class->getName() !== $idClass = get_class($value)) {
            $identifiers = $em->getClassMetadata($idClass)->getIdentifierValues($value);
        } else {
            $identifiers = $class->getIdentifierValues($value);
        }

        if (!$identifiers) {
            return sprintf('object("%s")', $idClass);
        }

        array_walk($identifiers, function (&$id, $field) {
            if (!is_object($id) || $id instanceof \DateTimeInterface) {
                $idAsString = $this->formatValue($id, self::PRETTY_DATE);
            } else {
                $idAsString = sprintf('object("%s")', get_class($id));
            }

            $id = sprintf('%s => %s', $field, $idAsString);
        });

        return sprintf('object("%s") identified by (%s)', $idClass, implode(', ', $identifiers));
    }

}

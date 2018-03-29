# Fully Qualified Domain Name validator
For Symfony's Validator component to test if a value is a valid Fully Qualified Domain Name.

Install 
-------
composer require laxcorp/fqdn-validator-bundle

Add in app/AppKernel.php
------------------------
```php
$bundles = [
    new LaxCorp\FqdnValidatorBundle\FqdnValidatorBundle()
]
```

``` require parameters
catalog_cname: 'catalog.domain.ru'
```


Use in Entity
-------------
```
use LaxCorp\FqdnValidatorBundle\Validator\Constraints\FqdnEntity;
```

```php
/**
 *
 * @ORM\Entity
 *
 * @FqdnEntity(
 *     fieldFqdn="domain",
 *     ignoreNull=true
 * )
 */
 class ...

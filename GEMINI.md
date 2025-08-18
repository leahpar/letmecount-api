# GEMINI.md

This file provides guidance when working with code in this repository.

## Contexte

Ce document présente le cahier des charges général de l'API Let-me-count.
Let-me-count est une application web pour la gestion des comptes entre amis.

## KISS

- This repository is a small personnal Symfony for a side project.
- Don't bother with complexity, it's a simple project.
- KISS is the key.
- Don't hesitate to ask for more details if needed.

## DRY

- Don't repeat yourself, but don't over-engineer.
- If you find yourself repeating code, consider creating a service or a trait.
- Keep the codebase clean and maintainable.
- Avoid unnecessary complexity.

## Language, Framework & tools

- **PHP**: 8.2+
- **Symfony**: 7.3+

## Code quality tools
- 
- `php bin/console lint:yaml /config/xxx`: Lint YAML files
- `make stan`: PhpStan static analysis
- `make tests`: Run PHPUnit tests.

## Ressources utiles

- [Cahier des charges](doc/cahier_des_charges_v1.md)
- [Documentation de l'API](doc/openapi.json)

## Comment travailler

Pour chaque tâche :
- Implémenter la fonctionnalité
- Écrire quelques tests unitaires (si pertinent) ou d'endpoint api
- Commiter avec un message clair et en français

## Git

- Les commits ne doivent inclure que les fichiers modifiés par la tâche en cours, ignorer les autres fichiers modifiés par quelque chose d'autre.
- Les messages de commits doivent être concis et en français.
- Les messages de commits doivent commencer par le picto 🤖 et garder un message concernant les modifications seulement.

## Code Style Guidelines

- Les entités & DTO divers doivent avoir des propriétés public par défaut.
- Respecter les conventions PSR & Symfony.
- Utiliser des types PHP 8 pour les paramètres et les valeurs de retour.
- Les contrôleurs doivent être fins, la logique métier doit être déplacée dans des services.

## Inscructions diverses

- Ne JAMAIS editer le fichier `phpstan.neon`.


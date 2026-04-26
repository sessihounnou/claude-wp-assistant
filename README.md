# Claude WP Assistant

> Plugin WordPress propulsé par **Claude AI (Anthropic)** — analyse automatique et correction des problèmes de performance, sécurité, SEO, erreurs PHP et conflits de plugins.

**Auteur :** [Biristools](https://biristools.com)  
**Licence :** GPL-2.0+  
**Requires WordPress :** 5.8+  
**Requires PHP :** 7.4+

---

## Fonctionnement

Claude WP Assistant connecte votre tableau de bord WordPress à l'API Claude d'Anthropic. Il collecte des données techniques du site, les envoie à Claude pour analyse, puis affiche un rapport structuré avec des actions de correction — certaines applicables en un clic.

```
WordPress Admin
     │
     ▼
[Collecte de données locales]   ← class-analyzer.php
     │  (PHP, DB, plugins, fichiers)
     ▼
[Envoi à l'API Claude]          ← class-api.php
     │  (claude-sonnet-4-6)
     ▼
[Rapport JSON structuré]
     │  { score, issues[], priority_action }
     ▼
[Affichage + Corrections auto]  ← class-fixer.php / admin.js
```

---

## Modules d'analyse

| Module | Ce qui est analysé |
|---|---|
| **Erreurs PHP** | Logs PHP, version PHP, `memory_limit`, `max_execution_time`, `display_errors` |
| **Performance** | Options autoload, transients expirés, révisions de posts, commentaires spam, corbeille, tables volumineuses, object cache, WP-Cron |
| **Plugins** | Plugins actifs/inactifs, mises à jour disponibles, conflits potentiels |
| **Sécurité** | HTTPS, éditeur de fichiers, clés secrètes, préfixe DB, debug display, utilisateurs admin, XMLRPC |
| **SEO technique** | Sitemap, robots.txt, plugin SEO, structure des permaliens, visibilité du blog, pages sans balises meta |

Chaque analyse produit un **score /100** et une liste de problèmes classés par sévérité (`critical`, `warning`, `info`).

---

## Corrections automatiques disponibles

Les corrections suivantes peuvent être appliquées depuis l'interface sans quitter WordPress :

| fix_id | Action |
|---|---|
| `clear_expired_transients` | Supprime les transients expirés de la base de données |
| `delete_post_revisions` | Supprime toutes les révisions de posts |
| `delete_spam_comments` | Supprime les commentaires marqués comme spam |
| `delete_trashed_posts` | Vide définitivement la corbeille |
| `disable_file_editor` | Ajoute `DISALLOW_FILE_EDIT` dans `wp-config.php` |
| `enable_debug_log` | Active `WP_DEBUG` + `WP_DEBUG_LOG` dans `wp-config.php` |
| `disable_debug_display` | Désactive `WP_DEBUG_DISPLAY` pour masquer les erreurs en frontend |
| `create_robots_txt` | Crée un fichier `robots.txt` avec les règles de base |

---

## Installation

1. Télécharger la dernière version depuis les [Releases GitHub](https://github.com/biristools/claude-wp-assistant/releases)
2. Dans WordPress : **Extensions > Ajouter > Téléverser une extension**
3. Activer le plugin
4. Aller dans **Claude AI** dans le menu d'administration
5. Saisir votre clé API Anthropic (disponible sur [console.anthropic.com](https://console.anthropic.com))

---

## Mises à jour automatiques

Le plugin se met à jour directement depuis WordPress dès qu'une nouvelle **GitHub Release** est publiée sur ce dépôt.

**Processus de release :**

```bash
# 1. Bumper la version dans claude-wp-assistant.php (CWPA_VERSION + Plugin header)
# 2. Créer le tag git
git tag v1.1.0
git push origin v1.1.0

# 3. Créer la Release sur GitHub et joindre le ZIP
#    (nom du fichier : claude-wp-assistant.zip)
gh release create v1.1.0 claude-wp-assistant.zip \
  --title "v1.1.0 - Description" \
  --notes "Changelog..."
```

WordPress vérifie les nouvelles versions toutes les 12h. Les administrateurs voient la notification de mise à jour dans **Tableau de bord > Mises à jour** comme pour n'importe quel plugin officiel.

---

## Chat intégré

Un assistant conversationnel permet de poser des questions libres sur votre site WordPress directement depuis l'interface du plugin. Claude répond en tenant compte du contexte de vos dernières analyses.

---

## Structure du projet

```
claude-wp-assistant/
├── claude-wp-assistant.php   # Point d'entrée, constantes, activation
├── includes/
│   ├── class-analyzer.php    # Collecte des données WordPress
│   ├── class-api.php         # Appels API Claude (Anthropic)
│   ├── class-fixer.php       # Corrections automatiques
│   ├── class-updater.php     # Auto-update depuis GitHub Releases
│   └── class-admin.php       # Menu WP, AJAX handlers
├── templates/
│   └── admin-page.php        # Template HTML de l'interface
└── assets/
    ├── css/admin.css          # Styles (dark mode, design system)
    └── js/admin.js            # Interactions, rendu des résultats, chat
```

---

## Sécurité

- Toutes les requêtes AJAX sont protégées par nonce WordPress (`cwpa_nonce`)
- Seuls les utilisateurs `manage_options` peuvent exécuter des analyses ou des corrections
- La clé API est stockée dans `wp_options` (chiffrée côté Anthropic, jamais exposée en frontend)
- Les données envoyées à Claude ne contiennent pas de données personnelles d'utilisateurs

---

## Support & Contact

- Site : [biristools.com](https://biristools.com)
- Issues : [GitHub Issues](https://github.com/biristools/claude-wp-assistant/issues)

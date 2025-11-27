# Bundled CMDB
Small CMDB project that uses ESET data sent to database for asset management, uses Keycloak as SSO provider for user authentication, S3 for file submission and access related to each asset

<!-- buttons -->
[![Stars](https://img.shields.io/github/stars/ivancarlosti/bundledcmdb?label=⭐%20Stars&color=gold&style=flat)](https://github.com/ivancarlosti/bundledcmdb/stargazers)
[![Watchers](https://img.shields.io/github/watchers/ivancarlosti/bundledcmdb?label=Watchers&style=flat&color=red)](https://github.com/sponsors/ivancarlosti)
[![Forks](https://img.shields.io/github/forks/ivancarlosti/bundledcmdb?label=Forks&style=flat&color=ff69b4)](https://github.com/sponsors/ivancarlosti)
[![GitHub commit activity](https://img.shields.io/github/commit-activity/m/ivancarlosti/bundledcmdb?label=Activity)](https://github.com/ivancarlosti/bundledcmdb/pulse)
[![GitHub Issues](https://img.shields.io/github/issues/ivancarlosti/bundledcmdb?label=Issues&color=orange)](https://github.com/ivancarlosti/bundledcmdb/issues)
[![License](https://img.shields.io/github/license/ivancarlosti/bundledcmdb?label=License)](LICENSE)  
[![GitHub last commit](https://img.shields.io/github/last-commit/ivancarlosti/bundledcmdb?label=Last%20Commit)](https://github.com/ivancarlosti/bundledcmdb/commits)
[![Security](https://img.shields.io/badge/Security-View%20Here-purple)](https://github.com/ivancarlosti/bundledcmdb/security)
[![Code of Conduct](https://img.shields.io/badge/Code%20of%20Conduct-2.1-4baaaa)](https://github.com/ivancarlosti/bundledcmdb?tab=coc-ov-file)
[![GitHub Sponsors](https://img.shields.io/github/sponsors/ivancarlosti?label=GitHub%20Sponsors&color=ffc0cb)][sponsor]
<!-- endbuttons -->

## Requirement:

* [Docker Compose](https://docs.docker.com/engine/install/)
* Keycloak for SSO
* MariaDB for database
* S3-like for object storage
* ESET data to populate

## Deployment instructions:

* Download `/docker` files on your server, example:
```
curl -o .env https://raw.githubusercontent.com/ivancarlosti/bundledcmdb/main/docker/.env
curl -o docker-compose.yml https://raw.githubusercontent.com/ivancarlosti/bundledcmdb/main/docker/docker-compose.yml
```
* Edit both `.env`, `docker-compose.yml` files
* Start Docker Compose, example:
```
docker compose pull && docker compose up -d
```

## Service setup:

* Run `setup.php` on browser to set username and password
* Run `index.php` on browser to login
* Access `Manage AWS Credentials` menu and fill required fields
* Access `Manage DDNS Entries` to add, edit and delete DDNS entries
* (optional) Access `Manage Users` to create new users, add/edit reCAPTCHA keys
* (optional) Access `View All Logs` to check last 30 days of entries created and/or updated

IAM required policy, remember to update `YOURZONEID` value to related domain zone ID and `subdomain.example.com.` to your domain or subdomain for service usage:

```
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "route53:ChangeResourceRecordSets",
        "route53:ListResourceRecordSets"
      ],
      "Resource": "arn:aws:route53:::hostedzone/YOURZONEID",
      "Condition": {
        "ForAllValues:StringLike": {
          "route53:ChangeResourceRecordSetsNormalizedRecordNames": [
            "*.subdomain.example.com."
          ]
        }
      }
    },
    {
      "Effect": "Allow",
      "Action": "route53:GetChange",
      "Resource": "*"
    }
  ]
}
```

## DDNS cURL update format:

To simplify the process, `[FQDN]` is also used as login for basic auth purposes.

Example: `https://[FQDN]:[PASSWORD]@subdomain.example.com/update.php?hostname=[FQDN]&myip=[IP]`

### Tested DDNS Custom URL:

TP-Link Omada Update URL:
* `https://[USERNAME]:[PASSWORD]@subdomain.example.com/update.php?hostname=[DOMAIN]&myip=[IP]`

## To Do:

* HTML beautification
* Build releases using Compose to populate AWS SDK dinamically

## Hosting note:

Using PHP with the Suhosin patch is not recommended, but is common on some Ubuntu and Debian distributions. To modify `suhosin.ini`, add the following line.

```
suhosin.executor.include.whitelist = phar
```

<!-- footer -->
---

## 🧑‍💻 Consulting and technical support
* For personal support and queries, please submit a new issue to have it addressed.
* For commercial related questions, please contact me directly for consulting costs. 

## 🩷 Project support
| If you found this project helpful, consider |
| :---: |
[**buying me a coffee**][buymeacoffee], [**donate by paypal**][paypal], [**sponsor this project**][sponsor] or just [**leave a star**](../..)⭐
|Thanks for your support, it is much appreciated!|

## 🌐 Connect with me
[![LinkedIn](https://img.shields.io/badge/LinkedIn-@ivancarlos-0077B5)](https://www.linkedin.com/in/ivancarlos)
[![X](https://img.shields.io/badge/X-@ivancarlos-000000)](https://x.com/ivancarlos)  
[![Telegram](https://img.shields.io/badge/Telegram-@ivancarlos-26A5E4)](https://t.me/ivancarlos)
[![Discord](https://img.shields.io/badge/Discord-@ivancarlos.me-5865F2)](https://icc.gg/discord)
[![Reddit](https://img.shields.io/badge/Reddit-@ivandoomer-5865F2)](https://icc.gg/reddit)  
[![Website](https://img.shields.io/badge/Website-ivancarlos.me-FF6B6B)](https://ivancarlos.me)

[cc]: https://docs.github.com/en/communities/setting-up-your-project-for-healthy-contributions/adding-a-code-of-conduct-to-your-project
[contributing]: https://docs.github.com/en/articles/setting-guidelines-for-repository-contributors
[security]: https://docs.github.com/en/code-security/getting-started/adding-a-security-policy-to-your-repository
[support]: https://docs.github.com/en/articles/adding-support-resources-to-your-project
[it]: https://docs.github.com/en/communities/using-templates-to-encourage-useful-issues-and-pull-requests/configuring-issue-templates-for-your-repository#configuring-the-template-chooser
[prt]: https://docs.github.com/en/communities/using-templates-to-encourage-useful-issues-and-pull-requests/creating-a-pull-request-template-for-your-repository
[funding]: https://docs.github.com/en/articles/displaying-a-sponsor-button-in-your-repository
[ivancarlos]: https://ivancarlos.me
[buymeacoffee]: https://www.buymeacoffee.com/ivancarlos
[paypal]: https://icc.gg/donate
[sponsor]: https://github.com/sponsors/ivancarlosti

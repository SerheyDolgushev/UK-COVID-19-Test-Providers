# UK COVID-19 Test Providers

Right now there are a few COVID-19 test types in UK:

- [Fit to Fly](https://www.gov.uk/guidance/coronavirus-covid-19-safer-air-travel-guidance-for-passengers#before-booking-a-flight--travel-restrictions) - Test for international travelers who are leaving UK (depends on the travel destination).
- [Day 2 and day 8 for international arrivals](https://www.gov.uk/guidance/testing-on-day-2-and-day-8-for-international-arrivals) - Two tests which all international arrivals are required to take.
- [Test to Release](https://www.gov.uk/guidance/coronavirus-covid-19-test-to-release-for-international-travel) - Voluntary test for international arrivals, which is taken on day 5. If it is negative you can stop quarantine but still [required to take Day 8 test](https://www.gov.uk/guidance/coronavirus-covid-19-test-to-release-for-international-travel#if-you-test-negative).

There are a few sources for all testing providers lists. Those lists seem to be updated manually, and they do not have up to date prices, and sometimes they are just outdated.

This tool is designed to fetch the prices for all testing types directly from the provider's websites. So prices are relevant and up to date. And you can use this tool to find the providers with the cheapest prices.

## How it works

1. The providers list is fetched from https://assets.publishing.service.gov.uk/government/uploads/system/uploads/attachment_data/file/977035/covid-private-testing-providers-general-testing-080421.csv/preview. 

2. The script tries to find all COVID-19 test type links on each provider's website.
   
3. Each test type has predefined list of words:
   - Fit to Fly: `fit`, `fly`
   - Day 2 & 8: `2`, `two`, `8`, `eight`, `arrival`
   - Test to Release: `release`, `covid`

   The script extracts words from all links (URI and text) on the provider main page. And uses only matched links for each test type. The links with most words matched have higher priority.  

4. If the matched link has the price in its text it will be used. Otherwise, the link URI is requested.

5. If the requested page has special "price" HTML elements the script will try to extract the price from them.

6. All the prices are extracted from the requested page. Depending on the test type, some extracted prices are ignored: less than £151 for Day 2 & 8, and less than £50 for other test types. The minimal price is used from the non ignored (valid) ones.

## Installation

1. Clone the tool
    ```bash
    git clone git@github.com:SerheyDolgushev/UK-COVID-19-Test-Providers.git
    cd UK-COVID-19-Test-Providers
    ```

2. Update the dependencies
    ```bash
    composer install
    ```

3. Run the crawler script:
    ```bash
    php bin/console uk-covid-test-providers:parse-prices
    ```

4. Check the CSV file with prices:
    ```bash
    cat var/data/provider_prices.csv
    ```